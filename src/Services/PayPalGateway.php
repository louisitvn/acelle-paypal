<?php

namespace Acelle\Paypal\Services;

use Acelle\Paypal\Support\PayPalApi;
use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\Contracts\ManageRemoteSubscriptionInterface;
use App\Cashier\Contracts\SupportsRemoteCatalogInterface;
use App\Cashier\Contracts\SupportsRemoteHostedCheckout;
use App\Cashier\DTO\BillingOrigin;
use App\Cashier\DTO\CheckoutHandle;
use App\Cashier\DTO\DirectCheckout;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\PaymentMethodDTO;
use App\Cashier\DTO\PollableCheckout;
use App\Cashier\DTO\RemoteCheckoutSessionDTO;
use App\Cashier\DTO\RemoteInvoiceDTO;
use App\Cashier\DTO\RemotePaymentFailureDTO;
use App\Cashier\DTO\RemotePlanDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use Carbon\Carbon;

/**
 * PayPal payment gateway (Orders v2 one-off + Subscriptions v1 remote subscription).
 *
 * ONE merged driver, BOTH directives (like the merged Stripe gateway):
 *   - $intent->subscription === null → a one-off charge. Orders v2 hosted checkout: the buyer
 *     approves an Order, the plugin captures it on return. Handle = {@see DirectCheckout} pointing at
 *     the plugin's own PayPalCheckoutController.
 *   - $intent->subscription !== null → a PROVIDER-MANAGED subscription. Subscriptions v1: the plugin
 *     creates a subscription against the mapped PayPal Billing Plan id (P-…), the buyer approves at
 *     PayPal, and it becomes ACTIVE. Handle = {@see PollableCheckout}; the host polls
 *     {@see getCheckoutSession} (webhook-independent) to detect activation and links it.
 *
 * Capability interfaces:
 *   - IntentGatewayInterface        — the base (one-off + subscription checkout)
 *   - SupportsRemoteHostedCheckout  — poll the subscription session to completion
 *   - ManageRemoteSubscriptionInterface — read/cancel/resume a subscription + read its card
 *   - SupportsRemoteCatalogInterface    — PayPal Billing Plans catalog + invoice history
 *
 * DELIBERATELY NOT declared — {@see \App\Cashier\Contracts\SupportsRemotePlanChange}. PayPal's
 * `revise` is not a merchant-side plan switch: a PayPal-funded subscription needs the SUBSCRIBER to
 * log in and re-consent, and "If re-consent is not done or if it fails, the subscription continues to
 * be billed on the existing plan"; a card-funded one does flip, but bills nothing now because
 * "Proration and one-time fees aren't automatically supported" and "The new price is effective
 * starting on the next billing cycle". Either way the app cannot switch a plan and collect the
 * difference in one call, so it declines the capability rather than faking it — the host then refuses
 * the plan change up front and tells the customer to cancel and resubscribe. Having the CATALOG does
 * not imply being able to MOVE a subscription inside it; that is precisely why the two interfaces are
 * separate.
 *
 * PayPal API gaps honestly handled (PayPal Subscriptions v1 has no equivalent). Each is either a
 * documented degrade or a named REFUSAL — never a fake success, because a fake here is money:
 *   - No "list all subscriptions" endpoint → {@see getRemoteSubscriptions} returns empty (the admin
 *     remote-subscriptions overview shows nothing; the per-sub sync + everything else works, since the
 *     host syncs by id via {@see getRemoteSubscription}).
 *   - No single sub-transaction retrieve → {@see getRemoteInvoice} returns null (caller falls back to
 *     the {@see getRemoteInvoices} list on the next sync). No per-transaction failure object AT ALL →
 *     {@see getRemoteInvoiceFailure} REFUSES rather than claim there is nothing to explain.
 *   - No merchant-hosted card-replacement page, no by-id stored-card lookup, no API to repoint a live
 *     subscription's funding source → {@see getCardUpdateUrl}, {@see resolveStoredPaymentMethod} and
 *     {@see setRemoteSubscriptionPaymentMethod} REFUSE. The subscriber changes the funding source in
 *     their own PayPal account (Settings → Payments → Automatic payments).
 *   - Cancel is IMMEDIATE + terminal (no cancel-at-period-end) → {@see cancelRemoteSubscription} ends
 *     the subscription now.
 *
 * Pull-only: no webhook listener. Completion is the browser-return reconcile (PayPalReturnController)
 * + the host cron poller. Pure — no DB writes, no handler callbacks.
 *
 * @link https://developer.paypal.com/docs/api/orders/v2/
 * @link https://developer.paypal.com/docs/api/subscriptions/v1/
 * @link https://developer.paypal.com/docs/subscriptions/customize/revise-subscriptions/
 */
class PayPalGateway implements
    IntentGatewayInterface,
    SupportsRemoteHostedCheckout,
    ManageRemoteSubscriptionInterface,
    SupportsRemoteCatalogInterface
{
    public const TYPE = 'paypal';

    /** How long a started subscription checkout is polled before an unapproved one is treated as expired. */
    private const SUBSCRIPTION_CHECKOUT_WINDOW_MIN = 180;

    private PayPalApi $api;

    public function __construct(string $clientId, string $clientSecret, string $environment = 'sandbox')
    {
        $this->api = new PayPalApi($clientId, $clientSecret, $environment);
    }

    public function api(): PayPalApi
    {
        return $this->api;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  IntentGatewayInterface
    // ──────────────────────────────────────────────────────────────────────

    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl, ?string $cancelUrl = null): CheckoutHandle
    {
        // SUBSCRIPTION — create a PayPal subscription against the mapped Billing Plan and hand back its
        // approve URL as a pollable session (the host stamps the subscription id + polls to activation).
        if ($intent->subscription !== null) {
            return $this->buildSubscriptionCheckout($intent, $returnUrl, $cancelUrl);
        }

        // ONE-OFF — the plugin's own controller builds + captures the Order (a pure getCheckoutUrl:
        // no HTTP side effect at intent-create time; the vendor call happens in the controller).
        return new DirectCheckout(
            route('paypal.checkout', ['intent_uid' => $intent->uid])
            . '?return_url=' . urlencode($returnUrl)
        );
    }

    /**
     * Create a provider-managed PayPal subscription (Subscriptions v1) against the mapped Billing Plan
     * id and return its approve URL as a {@see PollableCheckout}. custom_id echoes the intent uid so
     * the host can attribute the subscription back to the local PaymentIntent.
     */
    private function buildSubscriptionCheckout(PaymentIntent $intent, string $returnUrl, ?string $cancelUrl): PollableCheckout
    {
        $planId = trim((string) $intent->subscription->remotePlanId);
        if ($planId === '') {
            throw new \LogicException(
                'PayPal subscription requires a mapped PayPal Billing Plan id (P-…). '
                . 'Map the local plan to a PayPal plan in admin → Plan → Remote mapping.'
            );
        }

        // Route the buyer back through our own return controller (keyed by intent_uid, carrying the
        // merchant return_url) — it reconciles THIS intent off the stamped subscription id.
        $browserReturn = route('paypal.return', ['intent_uid' => $intent->uid])
            . '?return_url=' . urlencode($returnUrl);

        $body = [
            'plan_id'   => $planId,
            'custom_id' => $intent->uid,   // echoed back on GET — anti-forge attribution
            'application_context' => [
                'user_action'         => 'SUBSCRIBE_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url'          => $browserReturn,
                'cancel_url'          => $browserReturn . '&cancel=1',
            ],
        ];
        $email = trim((string) ($intent->payer->email ?? ''));
        if ($email !== '') {
            $body['subscriber'] = ['email_address' => $email];
        }

        $resp    = $this->api->post('/v1/billing/subscriptions', $body);
        $subId   = (string) ($resp['id'] ?? '');
        $approve = PayPalApi::linkRel($resp['links'] ?? [], 'approve');
        if ($subId === '' || !$approve) {
            throw new \RuntimeException('PayPal: subscription create returned no id / approve link');
        }

        return new PollableCheckout(
            url:       $approve,
            sessionId: $subId,   // host stamps this on intent.remote_reference_id
            // Non-null so the host cron poller (scopePendingCheckoutSessions) picks it up as the durable
            // backstop if the buyer approves but the browser-return doesn't fire.
            expiresAt: time() + self::SUBSCRIPTION_CHECKOUT_WINDOW_MIN * 60,
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SupportsRemoteHostedCheckout — poll the subscription session
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Read back a subscription checkout by its PayPal subscription id (= the stamped session id). Once
     * the subscription is ACTIVE the checkout is complete (remoteSubscriptionId set → the host links it
     * via onSubscriptionCreated); CANCELLED/EXPIRED → expired; else still open (awaiting approval). Pure.
     */
    public function getCheckoutSession(string $sessionId): RemoteCheckoutSessionDTO
    {
        $sub        = $this->api->get("/v1/billing/subscriptions/{$sessionId}");
        $ppStatus   = strtoupper((string) ($sub['status'] ?? ''));
        $customerId = isset($sub['subscriber']['payer_id']) ? (string) $sub['subscriber']['payer_id'] : null;

        // Explicit mapping — no silent fall-through.
        [$status, $paymentStatus, $rsid] = match ($ppStatus) {
            'ACTIVE'                          => [RemoteCheckoutSessionDTO::STATUS_COMPLETE, RemoteCheckoutSessionDTO::PAYMENT_PAID,   $sessionId],
            'CANCELLED', 'EXPIRED'            => [RemoteCheckoutSessionDTO::STATUS_EXPIRED,  RemoteCheckoutSessionDTO::PAYMENT_UNPAID, null],
            'APPROVAL_PENDING', 'APPROVED', 'SUSPENDED' => [RemoteCheckoutSessionDTO::STATUS_OPEN, RemoteCheckoutSessionDTO::PAYMENT_UNPAID, null],
            default => throw new \LogicException("PayPal getCheckoutSession: unknown subscription status '{$ppStatus}' for {$sessionId}"),
        };

        return new RemoteCheckoutSessionDTO(
            id:                   $sessionId,
            status:               $status,
            paymentStatus:        $paymentStatus,
            remoteSubscriptionId: $rsid,
            remoteCustomerId:     $customerId,
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    //  ManageRemoteSubscriptionInterface — read / cancel / resume / card / webhook
    // ──────────────────────────────────────────────────────────────────────

    public function getRemoteSubscription(string $remoteSubscriptionId): RemoteSubscriptionDTO
    {
        return $this->subscriptionToDto($this->api->get("/v1/billing/subscriptions/{$remoteSubscriptionId}"));
    }

    /**
     * Cancel the subscription. NOTE: PayPal has no cancel-at-period-end — this ends the subscription
     * IMMEDIATELY (terminal). The buyer loses access now, not at the next billing date.
     */
    public function cancelRemoteSubscription(string $remoteSubscriptionId): void
    {
        $this->api->post("/v1/billing/subscriptions/{$remoteSubscriptionId}/cancel", [
            'reason' => 'Canceled by merchant',
        ]);
    }

    /**
     * Reactivate a SUSPENDED subscription. PayPal cancel is terminal, so a cancelled subscription cannot
     * be resumed (PayPal returns 422) — this only revives a suspended/paused one. Idempotent-ish: a
     * PayPal error propagates (the host only offers resume for a resumable sub).
     */
    public function resumeRemoteSubscription(string $remoteSubscriptionId): void
    {
        $this->api->post("/v1/billing/subscriptions/{$remoteSubscriptionId}/activate", [
            'reason' => 'Reactivated by merchant',
        ]);
    }

    public function getRemotePaymentMethod(string $remoteSubscriptionId): ?PaymentMethodDTO
    {
        $sub  = $this->api->get("/v1/billing/subscriptions/{$remoteSubscriptionId}");
        $card = $sub['subscriber']['payment_source']['card'] ?? null;
        $email = trim((string) ($sub['subscriber']['email_address'] ?? ''));

        if (is_array($card)) {
            $expiry = (string) ($card['expiry'] ?? '');   // PayPal: "YYYY-MM"
            [$expYear, $expMonth] = strpos($expiry, '-') !== false ? array_pad(explode('-', $expiry, 2), 2, '') : ['', ''];
            return new PaymentMethodDTO(
                cardType:       trim((string) ($card['brand'] ?? '')) ?: null,
                last4:          trim((string) ($card['last_digits'] ?? '')) ?: null,
                expirationDate: ($expMonth && $expYear) ? sprintf('%s/%s', $expMonth, $expYear) : null,
                email:          $email !== '' ? $email : null,
                type:           'card',
                // Provider-managed: PayPal auto-charges the subscription itself; the host never
                // off-session charges it, so autoCharge stays false (this card is display-only).
            );
        }

        // PayPal-wallet subscription (no card) — surface the payer email for display.
        if ($email !== '') {
            return new PaymentMethodDTO(
                cardType:       'PayPal',
                last4:          null,
                expirationDate: null,
                email:          $email,
                type:           'paypal',
            );
        }

        return null;
    }

    /** Pull-only: no webhook configured, so core's webhook layer treats PayPal as "no webhook". */
    public function getWebhookSecret(): ?string
    {
        return null;
    }

    /**
     * Pull-only — no webhook endpoint is hosted. The shared cashier webhook controller is Stripe-bound
     * and never routes PayPal traffic; return the empty envelope shape the contract declares.
     *
     * @return array{event: string, data: array}
     */
    public function parseWebhookPayload(string $payload, array $headers): array
    {
        return ['event' => '', 'data' => []];
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SupportsRemoteCatalogInterface — plan catalog, subscription listing,
    //  invoice history, card replacement.
    //  Ordered + sub-bannered to MIRROR the interface, so a reviewer can diff this
    //  section against the contract top-to-bottom. (This class went uninstantiable
    //  once already because the contract grew and nobody noticed — see the
    //  contract-drift guard in PayPalGatewayTest.)
    // ──────────────────────────────────────────────────────────────────────

    // ── Plan catalog ──────────────────────────────────────────────────────

    /**
     * @return RemotePlanDTO[]
     */
    public function getRemotePlans(): array
    {
        $plans = [];
        $page  = 1;
        do {
            $resp  = $this->api->get('/v1/billing/plans', ['page_size' => 20, 'page' => $page, 'total_required' => 'true']);
            foreach ($resp['plans'] ?? [] as $plan) {
                // The list omits pricing; fetch each plan for its billing_cycles (price + interval).
                $plans[] = $this->getRemotePlan((string) ($plan['id'] ?? ''));
            }
            $totalPages = (int) ($resp['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= 25);  // hard cap: 25 pages (500 plans)

        return $plans;
    }

    public function getRemotePlan(string $remotePlanId): RemotePlanDTO
    {
        return $this->planToDto($this->api->get("/v1/billing/plans/{$remotePlanId}"));
    }

    // ── Subscription listing ──────────────────────────────────────────────

    /**
     * PayPal Subscriptions v1 has NO "list all subscriptions" endpoint (you track ids yourself), so the
     * admin remote-subscriptions overview is empty. The per-subscription sync + everything else works —
     * the host reconciles by id via {@see getRemoteSubscription}, not this list.
     *
     * @return array{data: RemoteSubscriptionDTO[], has_more: bool, next_cursor: ?string}
     */
    public function getRemoteSubscriptions(?string $startingAfter = null, int $limit = 100): array
    {
        return ['data' => [], 'has_more' => false, 'next_cursor' => null];
    }

    // ── Invoice history ───────────────────────────────────────────────────

    /**
     * List a subscription's billing transactions, oldest-first. PayPal requires a time window on the
     * transactions endpoint; we span the subscription's creation → now (full history each call; the
     * caller dedups on the transaction id).
     *
     * @return array{data: RemoteInvoiceDTO[], has_more: bool, next_cursor: ?string}
     */
    public function getRemoteInvoices(string $remoteSubscriptionId, ?string $afterId = null, int $limit = 50): array
    {
        $sub   = $this->api->get("/v1/billing/subscriptions/{$remoteSubscriptionId}");
        $start = isset($sub['create_time']) ? Carbon::parse((string) $sub['create_time']) : now()->subYears(3);

        try {
            $resp = $this->api->get("/v1/billing/subscriptions/{$remoteSubscriptionId}/transactions", [
                'start_time' => $start->toIso8601ZuluString(),
                'end_time'   => now()->toIso8601ZuluString(),
            ]);
        } catch (\RuntimeException $e) {
            // PayPal 404s the transactions endpoint for a subscription with no billing history yet
            // (still APPROVAL_PENDING, or never charged). That is "no invoices", not an error — degrade
            // to empty so the sync doesn't crash. Any other failure propagates.
            if (str_contains($e->getMessage(), '[404]')) {
                return ['data' => [], 'has_more' => false, 'next_cursor' => null];
            }
            throw $e;
        }

        $data = [];
        foreach ($resp['transactions'] ?? [] as $txn) {
            $dto = $this->transactionToInvoiceDto($txn, $remoteSubscriptionId);
            if ($dto !== null) {
                $data[] = $dto;
            }
        }
        usort($data, fn ($a, $b) => $a->billedAt <=> $b->billedAt);

        return ['data' => $data, 'has_more' => false, 'next_cursor' => null];
    }

    /**
     * PayPal Subscriptions v1 has no "retrieve one subscription transaction by id" endpoint — sub
     * transactions are only reachable through the windowed list above. Returns null; the caller falls
     * back to {@see getRemoteInvoices} on the next sync.
     */
    public function getRemoteInvoice(string $invoiceId): ?RemoteInvoiceDTO
    {
        return null;
    }

    /**
     * PayPal exposes no failure object addressable by a TRANSACTION id. The nearest signal,
     * `billing_info.last_failed_payment` (+ `failed_payments_count`), hangs off the SUBSCRIPTION and
     * describes "the last failure on this sub", not "why invoice X failed" — it cannot answer this
     * question, and there is no by-id retrieve to hang it on ({@see getRemoteInvoice} is null for the
     * same reason).
     *
     * Returning null would be a WRONG answer, not a degraded one: null is the contract's way of saying
     * "this invoice has no failed attempt to explain", which routes recovery down the keep-the-card /
     * 3-D-Secure path. "I cannot know" must not be dressed up as "nothing is wrong".
     *
     * Structurally unreachable today: the only caller reaches this after {@see getRemoteInvoice}
     * returned a DTO, so the PayPal lane bails out one step earlier. A throw here is therefore the
     * loud tripwire for a future caller that stops depending on that, not a live failure mode.
     */
    public function getRemoteInvoiceFailure(string $invoiceId): ?RemotePaymentFailureDTO
    {
        throw new \LogicException(
            'PayPal cannot explain a failed subscription charge by invoice id — Subscriptions v1 has no '
            . "per-transaction failure object (invoice id: {$invoiceId})."
        );
    }

    // ── Card replacement ──────────────────────────────────────────────────

    /**
     * PayPal has no hosted card-update page keyed by a customer. `$remoteCustomerId` here is a PayPal
     * `payer_id`, which identifies a PayPal ACCOUNT, not a merchant-side vault of stored cards — there
     * is no session to create against it. Changing the funding instrument on a PayPal subscription goes
     * through a subscriber-approved `revise` on that SUBSCRIPTION (an id this signature does not carry),
     * or the buyer's own PayPal account settings, which the merchant cannot drive.
     *
     * No host call site today. Throws so that wiring one up fails immediately and visibly rather than
     * handing a buyer a broken link.
     */
    public function getCardUpdateUrl(string $remoteCustomerId, string $returnUrl, string $cancelUrl): string
    {
        throw new \LogicException(
            'PayPal has no hosted card-update page for a payer — a funding-source change requires a '
            . 'subscriber-approved revise on the subscription itself.'
        );
    }

    /**
     * Paired with {@see getCardUpdateUrl} — with no such session to complete, there is no reference to
     * resolve. Null would claim "the session did not complete"; the truth is that no session can exist.
     */
    public function resolveStoredPaymentMethod(string $sessionReference): ?string
    {
        throw new \LogicException(
            'PayPal has no card-update session to resolve — getCardUpdateUrl() is unsupported on this gateway.'
        );
    }

    /**
     * PayPal Subscriptions v1 has no "make this stored instrument the default" write. The funding
     * source is chosen by the subscriber during approval and can only be changed by another
     * subscriber-approved revise; the merchant holds no reusable payment-method id to assign (which is
     * also why this gateway does not declare SupportsAutoChargeInterface — PayPal charges the sub).
     */
    public function setRemoteSubscriptionPaymentMethod(string $remoteSubscriptionId, string $remotePaymentMethodId): void
    {
        throw new \LogicException(
            'PayPal cannot be told which stored instrument to charge — the subscriber owns that choice '
            . 'and changes it only by approving a revise.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Plugin-internal — one-off (Orders v2), called by the controllers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create a one-off PayPal Order (intent=CAPTURE). Returns the full Orders v2 response — the caller
     * pulls `links[rel=approve].href` for the 302 redirect and `id` to stamp on the intent.
     */
    public function createOrder(
        string $intentUid,
        float $amountMajor,
        string $currency,
        string $description,
        string $returnUrl,
        string $cancelUrl,
    ): array {
        $iso   = PayPalApi::assertSupportedCurrency($currency);
        $value = PayPalApi::formatAmount($amountMajor, $iso);

        return $this->api->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $intentUid,
                'description'  => substr($description, 0, 127),
                'amount'       => ['currency_code' => $iso, 'value' => $value],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'return_url'  => $returnUrl,
                        'cancel_url'  => $cancelUrl,
                        'user_action' => 'PAY_NOW',
                    ],
                ],
            ],
        ]);
    }

    /** Capture an approved one-off Order. Returns the full Capture response. */
    public function captureOrder(string $paypalOrderId): array
    {
        return $this->api->post("/v2/checkout/orders/{$paypalOrderId}/capture", []);
    }

    public function fetchOrder(string $paypalOrderId): array
    {
        return $this->api->get("/v2/checkout/orders/{$paypalOrderId}");
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Private — PayPal JSON → DTO mappers
    // ──────────────────────────────────────────────────────────────────────

    /** Map a PayPal subscription status to the canonical RemoteSubscriptionDTO vocabulary (no fall-through). */
    private function mapSubStatus(string $ppStatus): string
    {
        return match (strtoupper($ppStatus)) {
            'ACTIVE'                        => 'active',
            'APPROVAL_PENDING', 'APPROVED'  => 'incomplete',
            'SUSPENDED'                     => 'paused',
            'CANCELLED', 'EXPIRED'          => 'canceled',
            default => throw new \InvalidArgumentException("PayPal: unmapped subscription status '{$ppStatus}'"),
        };
    }

    private function subscriptionToDto(array $sub): RemoteSubscriptionDTO
    {
        $billing     = $sub['billing_info'] ?? [];
        $lastPayment = $billing['last_payment'] ?? null;
        $ppStatus    = (string) ($sub['status'] ?? '');

        // `billing_info.last_payment` is {amount, time} — PayPal states NO status here. (Verified live
        // against an ACTIVE sub that had just collected $10: the key is simply absent.) An earlier
        // version read $lastPayment['status'] and mapped 'completed' → 'paid'; that is the Orders v2
        // CAPTURE vocabulary — the wrong API — so the branch never once fired. It is gone rather than
        // left as a comforting no-op.
        //
        // latestInvoiceStatus therefore stays null: "PayPal did not say", which is the truth. Do NOT
        // infer 'paid' from the mere presence of last_payment without first confirming on a FAILED
        // sandbox charge that PayPal leaves it untouched (it has a separate last_failed_payment +
        // failed_payments_count, which suggests it does — but suggests is not verified, and guessing
        // would badge a refused charge as paid in the admin UI). The authoritative status is already
        // available per transaction via getRemoteInvoices(); use that if the badge is ever needed.
        $latestAmount = isset($lastPayment['amount']['value']) ? (float) $lastPayment['amount']['value'] : null;

        return new RemoteSubscriptionDTO(
            id:                  (string) ($sub['id'] ?? ''),
            status:              $this->mapSubStatus($ppStatus),
            remotePlanId:        (string) ($sub['plan_id'] ?? ''),
            remoteCustomerId:    isset($sub['subscriber']['payer_id']) ? (string) $sub['subscriber']['payer_id'] : null,
            currentPeriodEnd:    isset($billing['next_billing_time']) ? Carbon::parse((string) $billing['next_billing_time']) : null,
            currentPeriodStart:  isset($lastPayment['time']) ? Carbon::parse((string) $lastPayment['time'])
                                    : (isset($sub['start_time']) ? Carbon::parse((string) $sub['start_time']) : null),
            canceledAt:          in_array($ppStatus, ['CANCELLED', 'EXPIRED'], true) && isset($sub['status_update_time'])
                                    ? Carbon::parse((string) $sub['status_update_time']) : null,
            latestInvoiceAmount: $latestAmount,
            latestInvoiceStatus: null,   // PayPal states none — see above
            latestInvoiceId:     null,
            metadata:            ['paypal_subscription' => $sub],
        );
    }

    /**
     * Map a PayPal Billing Plan → RemotePlanDTO. The REGULAR billing cycle carries the recurring price +
     * interval; a TRIAL cycle (if any) gives the trial length. PayPal plan prices are already major units.
     */
    private function planToDto(array $plan): RemotePlanDTO
    {
        $cycles  = $plan['billing_cycles'] ?? [];
        $regular = null;
        $trial   = null;
        foreach ($cycles as $c) {
            $tenure = strtoupper((string) ($c['tenure_type'] ?? ''));
            if ($tenure === 'REGULAR' && $regular === null) { $regular = $c; }
            if ($tenure === 'TRIAL'   && $trial === null)   { $trial = $c; }
        }
        $regular ??= ($cycles[0] ?? []);

        $freq     = $regular['frequency'] ?? [];
        $price    = $regular['pricing_scheme']['fixed_price'] ?? [];
        $currency = strtoupper((string) ($price['currency_code'] ?? 'USD'));

        $trialDays = null;
        if (is_array($trial)) {
            $tf = $trial['frequency'] ?? [];
            $trialDays = self::periodToDays((int) ($tf['interval_count'] ?? 0), (string) ($tf['interval_unit'] ?? ''))
                * max(1, (int) ($trial['total_cycles'] ?? 1));
        }

        return new RemotePlanDTO(
            id:            (string) ($plan['id'] ?? ''),
            name:          (string) ($plan['name'] ?? $plan['id'] ?? 'PayPal Plan'),
            price:         (float) ($price['value'] ?? 0),
            currency:      $currency,
            intervalCount: (int) ($freq['interval_count'] ?? 1),
            intervalUnit:  strtolower((string) ($freq['interval_unit'] ?? 'MONTH')),
            status:        strtolower((string) ($plan['status'] ?? 'active')),
            trialDays:     $trialDays,
        );
    }

    private static function periodToDays(int $count, string $unit): int
    {
        return match (strtoupper($unit)) {
            'DAY'   => $count,
            'WEEK'  => $count * 7,
            'MONTH' => $count * 30,
            'YEAR'  => $count * 365,
            default => 0,
        };
    }

    /** Map a PayPal subscription transaction → RemoteInvoiceDTO (only settled/refunded ones materialize). */
    private function transactionToInvoiceDto(array $txn, string $remoteSubscriptionId): ?RemoteInvoiceDTO
    {
        $ppStatus = strtoupper((string) ($txn['status'] ?? ''));

        // Map PayPal transaction status → app-level status (no silent fall-through into a wrong value).
        $status = match ($ppStatus) {
            'COMPLETED', 'PARTIALLY_REFUNDED' => 'paid',
            'REFUNDED'                        => 'refunded',
            'DECLINED', 'FAILED'              => 'failed',
            'PENDING'                         => 'open',
            default                           => null,   // unknown / not-yet-billed → don't materialize
        };
        if ($status === null) {
            return null;
        }

        $gross    = $txn['amount_with_breakdown']['gross_amount'] ?? [];
        $currency = strtoupper((string) ($gross['currency_code'] ?? 'USD'));
        $origin   = $ppStatus === 'REFUNDED' ? BillingOrigin::REFUND : BillingOrigin::RECURRING;

        return new RemoteInvoiceDTO(
            id:                   (string) ($txn['id'] ?? ''),
            remoteSubscriptionId: $remoteSubscriptionId,
            origin:               $origin,
            status:               $status,
            amount:               (float) ($gross['value'] ?? 0),
            currency:             $currency,
            periodStart:          null,
            periodEnd:            null,
            billedAt:             isset($txn['time']) ? Carbon::parse((string) $txn['time']) : now(),
            paymentMethodBrand:   trim((string) ($txn['payer_name']['alternate_full_name'] ?? '')) ?: null,
        );
    }
}

<?php

namespace Acelle\Paypal\Services;

use Acelle\Paypal\Support\PayPalApi;
use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\Contracts\RemoteSubscriptionGatewayInterface;
use App\Cashier\Contracts\SupportsSubscriptionInterface;
use App\Cashier\DTO\BillingOrigin;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\RemoteInvoiceDTO;
use App\Cashier\DTO\RemotePaymentMethodDTO;
use App\Cashier\DTO\RemotePlanDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use App\Cashier\DTO\SubscriptionResult;
use Carbon\Carbon;

/**
 * PayPal Payment Gateway — V1 (one-off + remote subscription).
 *
 * Implements:
 *   - IntentGatewayInterface             — base contract (one-off hosted checkout)
 *   - RemoteSubscriptionGatewayInterface — read/sync side (plans, subs, invoices)
 *   - SupportsSubscriptionInterface      — write side (createSubscription throws —
 *                                          PayPal subs only emerge from hosted checkout,
 *                                          not headless server-to-server)
 *
 * Pull-only design: no webhook listener. RemoteSubscriptionSyncService polls
 * subscription state on a cron; one-off completion is committed inline at
 * return-URL time via Capture API.
 *
 * Quirks vs other gateways:
 *   - Currency is ISO 4217 ALPHA ("USD", "EUR") — TBANK uses numeric ("840")
 *   - Amount is MAJOR units as decimal string ("49.00") — TBANK uses integer minor (4900)
 *   - PayPal returns rich error envelope `{name, message, details:[]}` — extractor in PayPalApi
 *   - JPY is the only zero-decimal currency in our V1 list; format as integer string
 *
 * @link https://developer.paypal.com/docs/api/orders/v2/
 * @link https://developer.paypal.com/docs/api/subscriptions/v1/
 */
class PayPalGateway implements
    IntentGatewayInterface,
    RemoteSubscriptionGatewayInterface,
    SupportsSubscriptionInterface
{
    public const TYPE = 'paypal';

    private const SUPPORTED_CURRENCIES = [
        'USD','EUR','GBP','CAD','AUD','JPY','CHF','NZD','SGD','HKD','SEK',
        'DKK','PLN','CZK','HUF','BRL','MXN','TWD','THB','PHP','MYR','ILS','NOK',
    ];

    private const ZERO_DECIMAL_CURRENCIES = ['JPY'];

    private PayPalApi $api;

    public function __construct(string $clientId, string $clientSecret, string $environment = 'sandbox')
    {
        $this->api = new PayPalApi($clientId, $clientSecret, $environment);
    }

    /** Expose underlying API for the controller layer (no SDK gymnastics). */
    public function api(): PayPalApi
    {
        return $this->api;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  IntentGatewayInterface
    // ──────────────────────────────────────────────────────────────────────

    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl): string
    {
        return route('paypal.checkout', ['intent_uid' => $intent->uid])
            . '?return_url=' . urlencode($returnUrl);
    }

    /** Display label for saved payment methods. V1 doesn't vault — returns generic. */
    public function getMethodTitle(array $billingData): string
    {
        return (string) ($billingData['card_type'] ?? 'PayPal');
    }

    public function getMethodInfo(array $billingData): string
    {
        $email = $billingData['payer_email'] ?? null;
        if ($email) {
            return (string) $email;
        }
        $last4 = $billingData['last_4'] ?? null;
        return $last4 ? '**** **** **** ' . $last4 : 'PayPal account';
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Plugin-internal (called by PayPalCheckoutController)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create a one-off PayPal Order. Returns the full Orders v2 response —
     * caller pulls `links[rel=approve].href` for the 302 redirect, and `id`
     * to stamp on intent.metadata['paypal_order_id'].
     */
    public function createOrder(
        string $intentUid,
        float $amountMajor,
        string $currency,
        string $description,
        string $returnUrl,
        string $cancelUrl,
    ): array {
        $iso   = $this->assertSupportedCurrency($currency);
        $value = $this->formatAmount($amountMajor, $iso);

        return $this->api->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $intentUid,
                'description'  => substr($description, 0, 127),
                'amount'       => [
                    'currency_code' => $iso,
                    'value'         => $value,
                ],
            ]],
            'application_context' => [
                'return_url'  => $returnUrl,
                'cancel_url'  => $cancelUrl,
                'user_action' => 'PAY_NOW',
            ],
        ]);
    }

    /**
     * Capture an approved Order. Called from the return controller after
     * customer approves at PayPal. Returns full Capture response (status,
     * purchase_units[].payments.captures[]).
     */
    public function captureOrder(string $paypalOrderId): array
    {
        return $this->api->post("/v2/checkout/orders/{$paypalOrderId}/capture", []);
    }

    public function fetchOrder(string $paypalOrderId): array
    {
        return $this->api->get("/v2/checkout/orders/{$paypalOrderId}");
    }

    /**
     * Create a PayPal Subscription against a PayPal Billing Plan.
     *
     * Distinct from the `createSubscription` interface method below (which
     * throws since headless server-to-server create isn't supported on PayPal).
     * This is the hosted-checkout entry: controller calls it, redirects browser
     * to the approve link, customer approves at PayPal, return URL committed.
     */
    public function createSubscriptionViaCheckout(
        string $intentUid,
        string $remotePlanId,
        string $customerEmail,
        string $returnUrl,
        string $cancelUrl,
    ): array {
        if (!$remotePlanId) {
            throw new \LogicException(
                "PayPal subscription requires remote_plan_id (PayPal BillingPlan ID). " .
                "Map the local plan to a PayPal plan in admin → Plan → Remote mapping."
            );
        }

        return $this->api->post('/v1/billing/subscriptions', [
            'plan_id'   => $remotePlanId,
            'custom_id' => $intentUid,
            'subscriber' => [
                'email_address' => $customerEmail,
            ],
            'application_context' => [
                'return_url'           => $returnUrl,
                'cancel_url'           => $cancelUrl,
                'user_action'          => 'SUBSCRIBE_NOW',
                'payment_method' => [
                    'payer_selected'   => 'PAYPAL',
                    'payee_preferred'  => 'IMMEDIATE_PAYMENT_REQUIRED',
                ],
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SupportsSubscriptionInterface
    // ──────────────────────────────────────────────────────────────────────

    /**
     * PayPal subscriptions only emerge from a customer-approved hosted-checkout
     * flow (no headless server-to-server creation). Throw explicitly so callers
     * are forced to use getCheckoutUrl() → controller calls createSubscriptionViaCheckout
     * → redirects to PayPal. Subscription state is picked up afterward via
     * getRemoteSubscription() during sync.
     */
    public function createSubscription(PaymentIntent $intent, array $pmData): SubscriptionResult
    {
        throw new \LogicException(
            'PayPal subscriptions are created via hosted checkout, not off-session API. ' .
            'Use getCheckoutUrl() and pull subscription state via getRemoteSubscription().'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    //  RemoteSubscriptionGatewayInterface — read/sync side
    // ──────────────────────────────────────────────────────────────────────

    /** @return RemotePlanDTO[] */
    public function getRemotePlans(): array
    {
        // PayPal Billing Plans: GET /v1/billing/plans?status=ACTIVE&page_size=20
        // Pagination via `links[rel=next].href`. We walk pages here so the
        // admin Plan-mapping picker sees the full catalog in one call.
        $plans = [];
        $url   = '/v1/billing/plans';
        $query = ['status' => 'ACTIVE', 'page_size' => 20, 'total_required' => 'true'];
        $hops  = 0;
        while ($url && $hops < 25) {     // safety: cap at 500 plans
            $response = $this->api->get($url, $query);
            foreach ($response['plans'] ?? [] as $plan) {
                // /v1/billing/plans returns summary objects without billing_cycles;
                // fetch full plan for accurate pricing.
                $full = $this->api->get("/v1/billing/plans/{$plan['id']}");
                $plans[] = $this->planToDto($full);
            }
            $url = $this->linkRel($response['links'] ?? [], 'next');
            $query = [];  // next URL already has query
            $hops++;
        }
        return $plans;
    }

    public function getRemotePlan(string $remotePlanId): RemotePlanDTO
    {
        return $this->planToDto($this->api->get("/v1/billing/plans/{$remotePlanId}"));
    }

    public function getRemoteSubscription(string $remoteSubscriptionId): RemoteSubscriptionDTO
    {
        return $this->subscriptionToDto($this->api->get("/v1/billing/subscriptions/{$remoteSubscriptionId}"));
    }

    /**
     * @return array{data: RemoteSubscriptionDTO[], has_more: bool, next_cursor: ?string}
     */
    public function getRemoteSubscriptions(?string $startingAfter = null, int $limit = 100): array
    {
        // PayPal exposes /v1/billing/subscriptions (no public LIST endpoint for
        // subscriptions in some sandbox regions). Where available, cursor token
        // sits in `links[rel=next].href` (?page=N&page_size=M). We pass `page=$startingAfter`
        // (treating cursor as page number) for compatibility with the
        // RemoteSubscriptionSyncService cursor-advance contract.
        $query = ['page_size' => min($limit, 100)];
        if ($startingAfter) {
            $query['page'] = (int) $startingAfter;
        }
        try {
            $response = $this->api->get('/v1/billing/subscriptions', $query);
        } catch (\RuntimeException $e) {
            // Some PayPal tenancies disable the list endpoint; sync layer still
            // works via per-sub `getRemoteSubscription` lookups against locally-
            // stored remote IDs. Surface empty page rather than crashing sync.
            \Log::warning('PayPal listSubscriptions not available; returning empty page', [
                'error' => $e->getMessage(),
            ]);
            return ['data' => [], 'has_more' => false, 'next_cursor' => null];
        }
        $data = array_map(
            fn ($sub) => $this->subscriptionToDto($sub),
            $response['subscriptions'] ?? []
        );
        $hasMore = $this->linkRel($response['links'] ?? [], 'next') !== null;
        $nextPage = $hasMore ? ((int) ($startingAfter ?? 1) + 1) : null;
        return [
            'data'        => $data,
            'has_more'    => $hasMore,
            'next_cursor' => $nextPage !== null ? (string) $nextPage : null,
        ];
    }

    public function cancelRemoteSubscription(string $remoteSubscriptionId): void
    {
        // PayPal: POST /v1/billing/subscriptions/{id}/cancel — body must include
        // a free-text reason; PayPal returns 204 No Content on success.
        $this->api->post("/v1/billing/subscriptions/{$remoteSubscriptionId}/cancel", [
            'reason' => 'Cancelled from Acelle',
        ]);
    }

    /**
     * PayPal resume: POST /v1/billing/subscriptions/{id}/activate (only valid
     * if the sub was SUSPENDED, not CANCELLED — once cancelled, PayPal won't
     * resurrect; admin must create a new subscription). Idempotent for already-
     * active subs (PayPal returns 422 we silently swallow only the "already
     * active" case — every other error propagates).
     */
    public function resumeRemoteSubscription(string $remoteSubscriptionId): void
    {
        try {
            $this->api->post("/v1/billing/subscriptions/{$remoteSubscriptionId}/activate", [
                'reason' => 'Resumed from Acelle',
            ]);
        } catch (\RuntimeException $e) {
            // "Already active" comes back as 422 — non-issue. Anything else: rethrow.
            if (!str_contains($e->getMessage(), 'SUBSCRIPTION_STATUS_INVALID')) {
                throw $e;
            }
        }
    }

    public function extractRemotePaymentMethodId(array $autobillingData): ?string
    {
        // PayPal hosted-checkout doesn't materialize a local PaymentMethod (PayPal
        // owns the vault); return null so admin invoice mapping falls through to
        // vendor-side payment-method display.
        return $autobillingData['paypal_payment_token_id'] ?? null;
    }

    public function updateRemoteSubscriptionPlan(
        string $remoteSubscriptionId,
        string $newRemotePlanId
    ): RemoteSubscriptionDTO {
        // PayPal: POST /v1/billing/subscriptions/{id}/revise — returns 200 with
        // a `links` block containing the customer-approval URL. Plan-change in
        // PayPal needs customer re-approval; for now we accept the API call as
        // "scheduled" and the customer is prompted at the next portal visit.
        // Real-time plan-change UX would require redirecting the customer to
        // the approve link — V1.1 work.
        $this->api->post("/v1/billing/subscriptions/{$remoteSubscriptionId}/revise", [
            'plan_id' => $newRemotePlanId,
        ]);
        return $this->getRemoteSubscription($remoteSubscriptionId);
    }

    public function getRemotePaymentMethod(string $remoteSubscriptionId): ?RemotePaymentMethodDTO
    {
        // PayPal anonymizes the funding source on most subscriptions —
        // typically only `subscriber.payer_id` and `email_address` are exposed.
        // No card last-4. Return what we can; sync UI falls back gracefully.
        $response = $this->api->get("/v1/billing/subscriptions/{$remoteSubscriptionId}");
        $subscriber = $response['subscriber'] ?? null;
        if (!$subscriber) {
            return null;
        }
        return new RemotePaymentMethodDTO(
            cardType:       null,
            last4:          null,
            expirationDate: null,
            email:          $subscriber['email_address'] ?? null,
            type:           'paypal',
        );
    }

    /** Pull-only: no webhook secret to surface, no webhook payload to verify. */
    public function getWebhookSecret(): ?string
    {
        return null;
    }

    public function parseWebhookPayload(string $payload, array $headers): array
    {
        return [];
    }

    /**
     * List Transactions for a subscription, oldest-first.
     *
     * PayPal endpoint: GET /v1/billing/subscriptions/{id}/transactions
     *   ?start_time={iso}&end_time={iso}   (BOTH required by PayPal!)
     *
     * Quirk: PayPal *requires* a time range. We default to last 5 years through
     * now if no $afterId — covers all realistic sync cases. Cursor (`$afterId`)
     * uses the transaction `id` returned by PayPal; we filter client-side after
     * `id > afterId` since PayPal doesn't expose a strict-after parameter.
     *
     * @return array{data: RemoteInvoiceDTO[], has_more: bool, next_cursor: ?string}
     */
    public function getRemoteInvoices(
        string $remoteSubscriptionId,
        ?string $afterId = null,
        int $limit = 50,
    ): array {
        $start = now()->subYears(5)->toIso8601String();
        $end   = now()->toIso8601String();

        $response = $this->api->get(
            "/v1/billing/subscriptions/{$remoteSubscriptionId}/transactions",
            ['start_time' => $start, 'end_time' => $end]
        );

        $all = [];
        foreach ($response['transactions'] ?? [] as $txn) {
            $dto = $this->transactionToInvoiceDto($txn, $remoteSubscriptionId);
            if ($dto !== null) {
                $all[] = $dto;
            }
        }
        // Oldest-first per RemoteSubscriptionGatewayInterface contract.
        usort($all, fn ($a, $b) => $a->billedAt->timestamp <=> $b->billedAt->timestamp);

        // Client-side cursor: keep only events strictly after $afterId.
        if ($afterId !== null) {
            $found = false;
            $all   = array_values(array_filter($all, function ($dto) use ($afterId, &$found) {
                if ($found) {
                    return true;
                }
                if ($dto->id === $afterId) {
                    $found = true;
                }
                return false;
            }));
        }

        $page    = array_slice($all, 0, $limit);
        $hasMore = count($all) > $limit;
        $lastId  = $page ? end($page)->id : null;
        return [
            'data'        => $page,
            'has_more'    => $hasMore,
            'next_cursor' => $hasMore ? $lastId : null,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Internal helpers
    // ──────────────────────────────────────────────────────────────────────

    private function assertSupportedCurrency(string $currency): string
    {
        $iso = strtoupper($currency);
        if (!in_array($iso, self::SUPPORTED_CURRENCIES, true)) {
            throw new \InvalidArgumentException(
                "PayPal: unsupported currency '{$currency}'. Supported: "
                . implode(', ', self::SUPPORTED_CURRENCIES) . '.'
            );
        }
        return $iso;
    }

    /**
     * PayPal Orders v2 wants `amount.value` as a *decimal string*. Zero-decimal
     * currencies (JPY in our list) must NOT have decimals — `"49.00"` is
     * rejected by PayPal as `INVALID_PARAMETER_VALUE` for JPY.
     */
    private function formatAmount(float $amountMajor, string $iso): string
    {
        if (in_array($iso, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (string) (int) round($amountMajor);
        }
        return number_format($amountMajor, 2, '.', '');
    }

    /** Find a link by rel from PayPal's HATEOAS links[] block. */
    private function linkRel(array $links, string $rel): ?string
    {
        foreach ($links as $link) {
            if (($link['rel'] ?? null) === $rel) {
                return $link['href'] ?? null;
            }
        }
        return null;
    }

    /**
     * Convert PayPal Plan → RemotePlanDTO. PayPal plan shape:
     *   id, name, description, status,
     *   billing_cycles[].{tenure_type, frequency.{interval_unit,interval_count},
     *                     pricing_scheme.fixed_price.{value,currency_code}}
     */
    private function planToDto(array $plan): RemotePlanDTO
    {
        // Pick the first REGULAR cycle (skip TRIAL cycles for primary pricing).
        $cycles = $plan['billing_cycles'] ?? [];
        $regular = null;
        $trial   = null;
        foreach ($cycles as $cycle) {
            $type = $cycle['tenure_type'] ?? '';
            if ($type === 'REGULAR' && $regular === null) {
                $regular = $cycle;
            } elseif ($type === 'TRIAL' && $trial === null) {
                $trial = $cycle;
            }
        }
        $regular = $regular ?? ($cycles[0] ?? []);

        $price = (float) ($regular['pricing_scheme']['fixed_price']['value'] ?? 0);
        $cur   = strtoupper((string) ($regular['pricing_scheme']['fixed_price']['currency_code'] ?? 'USD'));
        $freq  = $regular['frequency'] ?? [];

        $intervalUnit = match (strtoupper((string) ($freq['interval_unit'] ?? 'MONTH'))) {
            'DAY'   => 'day',
            'WEEK'  => 'week',
            'MONTH' => 'month',
            'YEAR'  => 'year',
            default => 'month',
        };

        $trialDays = null;
        if (is_array($trial)) {
            $tf = $trial['frequency'] ?? [];
            $tCount = (int) ($tf['interval_count'] ?? 0);
            $tUnit  = strtoupper((string) ($tf['interval_unit'] ?? ''));
            $trialDays = match ($tUnit) {
                'DAY'   => $tCount,
                'WEEK'  => $tCount * 7,
                'MONTH' => $tCount * 30,
                'YEAR'  => $tCount * 365,
                default => 0,
            };
        }

        return new RemotePlanDTO(
            id:            (string) $plan['id'],
            name:          (string) ($plan['name'] ?? $plan['id']),
            price:         $price,
            currency:      $cur,
            intervalCount: (int) ($freq['interval_count'] ?? 1),
            intervalUnit:  $intervalUnit,
            status:        strtolower((string) ($plan['status'] ?? 'active')),
            trialDays:     $trialDays,
        );
    }

    /**
     * Convert PayPal Subscription → RemoteSubscriptionDTO.
     *
     * PayPal subscription shape:
     *   id, status, plan_id, custom_id, subscriber.{payer_id, email_address},
     *   start_time, status_update_time,
     *   billing_info.{
     *       outstanding_balance.{value,currency_code},
     *       next_billing_time, last_payment.{amount,time}
     *   }
     */
    private function subscriptionToDto(array $sub): RemoteSubscriptionDTO
    {
        $billing = $sub['billing_info'] ?? [];
        $last    = $billing['last_payment'] ?? [];
        $lastAmount = isset($last['amount']['value']) ? (float) $last['amount']['value'] : null;
        $lastStatus = $lastAmount !== null ? 'paid' : null;

        $status = $this->normalizeSubscriptionStatus((string) ($sub['status'] ?? ''));

        return new RemoteSubscriptionDTO(
            id:                  (string) ($sub['id'] ?? ''),
            status:              $status,
            remotePlanId:        (string) ($sub['plan_id'] ?? ''),
            remoteCustomerId:    $sub['subscriber']['payer_id'] ?? null,
            currentPeriodEnd:    isset($billing['next_billing_time'])
                                    ? Carbon::parse($billing['next_billing_time'])
                                    : null,
            currentPeriodStart:  isset($last['time']) ? Carbon::parse($last['time']) : null,
            canceledAt:          ($status === 'canceled' && isset($sub['status_update_time']))
                                    ? Carbon::parse($sub['status_update_time'])
                                    : null,
            latestInvoiceAmount: $lastAmount,
            latestInvoiceStatus: $lastStatus,
        );
    }

    /**
     * PayPal subscription status → Acelle local-status string.
     * Mapping mirrors Paddle/Stripe convention used elsewhere in the codebase.
     */
    private function normalizeSubscriptionStatus(string $paypalStatus): string
    {
        return match (strtoupper($paypalStatus)) {
            'APPROVAL_PENDING', 'APPROVED' => 'incomplete',
            'ACTIVE'                       => 'active',
            'SUSPENDED'                    => 'paused',
            'CANCELLED', 'EXPIRED'         => 'canceled',
            default => strtolower($paypalStatus),
        };
    }

    /**
     * Convert PayPal subscription Transaction → RemoteInvoiceDTO.
     *
     * PayPal transaction shape (subscription-scoped):
     *   id, status, amount_with_breakdown.gross_amount.{value,currency_code},
     *   payer_email, time
     */
    private function transactionToInvoiceDto(array $txn, string $subscriptionId): ?RemoteInvoiceDTO
    {
        $id = (string) ($txn['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $gross = $txn['amount_with_breakdown']['gross_amount'] ?? [];
        $amount = (float) ($gross['value'] ?? 0);
        $currency = strtoupper((string) ($gross['currency_code'] ?? 'USD'));

        $paypalStatus = strtoupper((string) ($txn['status'] ?? ''));
        $status = match ($paypalStatus) {
            'COMPLETED'                => 'paid',
            'DECLINED', 'FAILED'       => 'failed',
            'PARTIALLY_REFUNDED', 'REFUNDED' => 'refunded',
            'PENDING'                  => 'open',
            default                    => strtolower($paypalStatus),
        };

        return new RemoteInvoiceDTO(
            id:                     $id,
            remoteSubscriptionId:   $subscriptionId,
            origin:                 BillingOrigin::RECURRING,  // V1: all sub-txns treated as recurring
            status:                 $status,
            amount:                 $amount,
            currency:               $currency,
            periodStart:            null,    // PayPal doesn't surface per-txn period on subscriptions
            periodEnd:              null,
            billedAt:               isset($txn['time']) ? Carbon::parse($txn['time']) : now(),
            failureReason:          null,
            hostedInvoiceUrl:       null,
            paymentMethodRemoteId:  null,
            paymentMethodBrand:     null,
            paymentMethodLast4:     null,
        );
    }
}

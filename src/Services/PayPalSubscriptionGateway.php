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
 * PayPal recurring subscription gateway (Billing Subscriptions API v1).
 *
 * Type: 'paypal-subscription' — remote-subscription (PayPal owns the billing
 * agreement + auto-charges customer's PayPal account each cycle). Used when
 * admin maps a local plan to a PayPal Billing Plan ID and customers subscribe
 * through PayPal.
 *
 * For one-off PayPal payments (no recurring), use sibling `PayPalGateway`
 * (type 'paypal') — direct gateway, supports card guest checkout.
 *
 * Pull-only design — no webhook listener. `RemoteSubscriptionSyncService`
 * polls via `getRemoteSubscription` / `getRemoteSubscriptions` /
 * `getRemoteInvoices` to detect new recurring charges + status changes.
 *
 * @link https://developer.paypal.com/docs/api/subscriptions/v1/
 */
class PayPalSubscriptionGateway implements
    IntentGatewayInterface,
    RemoteSubscriptionGatewayInterface,
    SupportsSubscriptionInterface
{
    public const TYPE = 'paypal-subscription';

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

    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl): string
    {
        return route('paypal-subscription.checkout', ['intent_uid' => $intent->uid])
            . '?return_url=' . urlencode($returnUrl);
    }

    public function getMethodTitle(array $billingData): string
    {
        return 'PayPal Subscription';
    }

    public function getMethodInfo(array $billingData): string
    {
        $email = $billingData['payer_email'] ?? null;
        return $email ? (string) $email : 'PayPal account';
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Plugin-internal (called by PayPalSubscriptionCheckoutController)
    // ──────────────────────────────────────────────────────────────────────

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
                    'payer_selected'  => 'PAYPAL',
                    'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED',
                ],
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SupportsSubscriptionInterface
    // ──────────────────────────────────────────────────────────────────────

    /**
     * PayPal subscriptions only emerge from a customer-approved hosted-checkout
     * flow (no headless server-to-server creation). Throw explicitly — callers
     * must use getCheckoutUrl() → controller → createSubscriptionViaCheckout
     * → redirect → return → getRemoteSubscription.
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
        $plans = [];
        $url   = '/v1/billing/plans';
        $query = ['status' => 'ACTIVE', 'page_size' => 20, 'total_required' => 'true'];
        $hops  = 0;
        while ($url && $hops < 25) {
            $response = $this->api->get($url, $query);
            foreach ($response['plans'] ?? [] as $plan) {
                $full = $this->api->get("/v1/billing/plans/{$plan['id']}");
                $plans[] = $this->planToDto($full);
            }
            $url = PayPalApi::linkRel($response['links'] ?? [], 'next');
            $query = [];
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

    /** @return array{data: RemoteSubscriptionDTO[], has_more: bool, next_cursor: ?string} */
    public function getRemoteSubscriptions(?string $startingAfter = null, int $limit = 100): array
    {
        $query = ['page_size' => min($limit, 100)];
        if ($startingAfter) {
            $query['page'] = (int) $startingAfter;
        }
        try {
            $response = $this->api->get('/v1/billing/subscriptions', $query);
        } catch (\RuntimeException $e) {
            \Log::warning('PayPal listSubscriptions not available; returning empty page', [
                'error' => $e->getMessage(),
            ]);
            return ['data' => [], 'has_more' => false, 'next_cursor' => null];
        }
        $data = array_map(fn ($sub) => $this->subscriptionToDto($sub), $response['subscriptions'] ?? []);
        $hasMore = PayPalApi::linkRel($response['links'] ?? [], 'next') !== null;
        $nextPage = $hasMore ? ((int) ($startingAfter ?? 1) + 1) : null;
        return [
            'data'        => $data,
            'has_more'    => $hasMore,
            'next_cursor' => $nextPage !== null ? (string) $nextPage : null,
        ];
    }

    public function cancelRemoteSubscription(string $remoteSubscriptionId): void
    {
        $this->api->post("/v1/billing/subscriptions/{$remoteSubscriptionId}/cancel", [
            'reason' => 'Cancelled from Acelle',
        ]);
    }

    public function resumeRemoteSubscription(string $remoteSubscriptionId): void
    {
        try {
            $this->api->post("/v1/billing/subscriptions/{$remoteSubscriptionId}/activate", [
                'reason' => 'Resumed from Acelle',
            ]);
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'SUBSCRIPTION_STATUS_INVALID')) {
                throw $e;
            }
        }
    }

    public function extractRemotePaymentMethodId(array $autobillingData): ?string
    {
        return $autobillingData['paypal_payment_token_id'] ?? null;
    }

    public function updateRemoteSubscriptionPlan(
        string $remoteSubscriptionId,
        string $newRemotePlanId
    ): RemoteSubscriptionDTO {
        $this->api->post("/v1/billing/subscriptions/{$remoteSubscriptionId}/revise", [
            'plan_id' => $newRemotePlanId,
        ]);
        return $this->getRemoteSubscription($remoteSubscriptionId);
    }

    public function getRemotePaymentMethod(string $remoteSubscriptionId): ?RemotePaymentMethodDTO
    {
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
     * PayPal endpoint: GET /v1/billing/subscriptions/{id}/transactions?start_time&end_time
     * Both time params REQUIRED by PayPal. Cursor: client-side strict-after on txn id.
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
        usort($all, fn ($a, $b) => $a->billedAt->timestamp <=> $b->billedAt->timestamp);

        if ($afterId !== null) {
            $found = false;
            $all   = array_values(array_filter($all, function ($dto) use ($afterId, &$found) {
                if ($found) return true;
                if ($dto->id === $afterId) $found = true;
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
    //  DTO mappers (vendor JSON shape → app DTO shape)
    // ──────────────────────────────────────────────────────────────────────

    private function planToDto(array $plan): RemotePlanDTO
    {
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

    private function normalizeSubscriptionStatus(string $paypalStatus): string
    {
        return match (strtoupper($paypalStatus)) {
            'APPROVAL_PENDING', 'APPROVED' => 'incomplete',
            'ACTIVE'                       => 'active',
            'SUSPENDED'                    => 'paused',
            'CANCELLED', 'EXPIRED'         => 'canceled',
            default                        => strtolower($paypalStatus),
        };
    }

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
            'COMPLETED'                      => 'paid',
            'DECLINED', 'FAILED'             => 'failed',
            'PARTIALLY_REFUNDED', 'REFUNDED' => 'refunded',
            'PENDING'                        => 'open',
            default                          => strtolower($paypalStatus),
        };

        return new RemoteInvoiceDTO(
            id:                     $id,
            remoteSubscriptionId:   $subscriptionId,
            origin:                 BillingOrigin::RECURRING,
            status:                 $status,
            amount:                 $amount,
            currency:               $currency,
            periodStart:            null,
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

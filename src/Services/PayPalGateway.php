<?php

namespace Acelle\Paypal\Services;

use Acelle\Paypal\Support\PayPalApi;
use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\Contracts\SupportsAutoChargeInterface;
use App\Cashier\DTO\CheckoutHandle;
use App\Cashier\DTO\DirectCheckout;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\PaymentMethodDTO;
use App\Cashier\DTO\PaymentResult;
use App\Cashier\Exceptions\UnsupportedCheckoutModeException;

/**
 * PayPal payment gateway (Orders API v2) — one-off checkout + app-driven
 * off-session auto-charge (MERGED single driver, DESIGN A).
 *
 * Type: 'paypal'. Two capabilities on ONE class:
 *   - IntentGatewayInterface        → buyer-present one-off hosted checkout.
 *   - SupportsAutoChargeInterface   → unattended off-session renewal charge
 *                                     against a saved PayPal-wallet vault id.
 *
 * Used when admin wants to accept PayPal payments for local-managed plans /
 * custom invoices. Customer redirects to PayPal hosted checkout — gets both
 * "Pay with PayPal" (account) AND "Pay with Debit or Credit Card" (guest).
 *
 * Vaulting policy = always-vault-when-supported (Stripe-consistent): the normal
 * one-off checkout asks PayPal to store the wallet in the vault ON_SUCCESS, and
 * the return controller persists the vault id as a saved PaymentMethod. A pure
 * one-off buyer with no subscription is simply never auto-charged (the host only
 * auto-charges renew order items) — harmless.
 *
 * NOTE on subscriptions: `autoCharge` is the app's OWN off-session cron path — it
 * is NOT a checkout directive. A PaymentIntent carrying a subscription spec means
 * a REMOTE (provider-managed) subscription was requested, which this vendor still
 * does NOT support — so getCheckoutUrl keeps rejecting it (fail loud) and the host
 * falls back to another method rather than silently charging a one-off.
 *
 * Pure service — no DB writes, no handler dispatch (autoCharge only calls the
 * PayPal API + returns a PaymentResult). Controller layer (`PayPalCheckoutController`
 * + `PayPalReturnController`) handles HTTP side effects and dispatches
 * `CheckoutHandlerInterface` on return.
 *
 * @link https://developer.paypal.com/docs/api/orders/v2/
 * @link https://developer.paypal.com/docs/checkout/save-payment-methods/purchase/paypal/
 */
class PayPalGateway implements
    IntentGatewayInterface,
    SupportsAutoChargeInterface
{
    public const TYPE = 'paypal';

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
        // One-off / local charge ONLY — PayPal here has no remote-subscription concept. A
        // subscription directive on the intent means the wrong gateway was picked for a
        // remote-sub plan; fail loud (the host catches this and offers another method) rather
        // than silently charging a one-off for a request that asked for recurring.
        if ($intent->subscription !== null) {
            throw new UnsupportedCheckoutModeException(
                "PaymentIntent {$intent->uid} carries a subscription spec — PayPal is one-off only and cannot start a remote subscription."
            );
        }

        return new DirectCheckout(
            route('paypal.checkout', ['intent_uid' => $intent->uid])
            . '?return_url=' . urlencode($returnUrl)
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SupportsAutoChargeInterface — called by the host renewal cron
    //  (SubscriptionManagementService::autoChargeRenewOrderItem)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Off-session renewal charge against the saved PayPal-wallet vault id. Merchant-
     * initiated, no buyer present — a single synchronous create-order-with-capture.
     *
     * PURE: only calls the PayPal API + maps the response into a PaymentResult. No DB
     * writes, no handler callbacks (the host settles the invoice from the result).
     *
     * The vault id was stashed on the saved PaymentMethod at first-payment time
     * (PayPalReturnController) and rides back as the DTO's `remotePaymentMethodId`
     * (and mirror `metadata['paypal_vault_id']`).
     *
     * Idempotency: `PayPal-Request-Id` is derived from the intent uid so a cron retry
     * of the SAME renewal never double-charges the wallet.
     */
    public function autoCharge(PaymentIntent $intent, PaymentMethodDTO $pm): PaymentResult
    {
        $vaultId = $pm->remotePaymentMethodId
            ?: ($pm->metadata['paypal_vault_id'] ?? null);
        if (!$vaultId) {
            return PaymentResult::failed(
                'PayPal auto-charge: missing paypal_vault_id on saved payment method'
            );
        }

        try {
            PayPalApi::assertSupportedCurrency((string) ($intent->currency ?: 'USD'));
        } catch (\InvalidArgumentException $e) {
            return PaymentResult::failed($e->getMessage());
        }

        try {
            $resp = $this->api->createVaultCharge(
                vaultId:        (string) $vaultId,
                referenceId:    $intent->uid,
                amountMajor:    (float) $intent->amount,
                currency:       (string) ($intent->currency ?: 'USD'),
                description:    (string) ($intent->description ?: 'Subscription renewal'),
                // Stable per-intent idempotency key — same renewal intent, same key.
                idempotencyKey: 'paypal-autocharge-' . $intent->uid,
            );
        } catch (\RuntimeException $e) {
            // PayPalApi wraps every BadResponseException as RuntimeException with the
            // extracted human reason. A hard API failure (network, auth, malformed) is
            // a hard decline for this attempt — host retries next cycle.
            return PaymentResult::failed('PayPal auto-charge failed: ' . $e->getMessage());
        }

        $orderId = (string) ($resp['id'] ?? ('paypal-autocharge-' . $intent->uid));
        $status  = strtoupper((string) ($resp['status'] ?? ''));

        // Map EVERY PayPal order status explicitly (no fall-through).
        return match ($status) {
            // Synchronous create-with-capture settled the charge.
            'COMPLETED' => PaymentResult::success($orderId, ['paypal_order_id' => $orderId]),

            // Cardholder authentication still required — impossible to satisfy in an
            // unattended cron. Surface as requiresAuth so the host emails the customer
            // to re-authorize; NEVER silently retry (that just re-declines forever).
            'PAYER_ACTION_REQUIRED' => PaymentResult::requiresAuth(
                // No 3DS clientSecret in the PayPal-wallet MIT flow — the customer must
                // re-approve via a fresh buyer-present checkout, not confirm a secret.
                // Pass the order id so the host has a remote ref to log/correlate.
                $orderId,
                $orderId
            ),

            // Hard declines / terminal failures — fail this attempt.
            'DECLINED', 'FAILED', 'VOIDED' => PaymentResult::failed(
                "PayPal auto-charge order status: {$status}",
                $orderId
            ),

            // Anything else (incl. empty/unknown) is an unhandled state: fail loud with
            // the raw status so on-call can grep it — never silently treat as success.
            default => PaymentResult::failed(
                "PayPal auto-charge returned unexpected order status: "
                . ($status !== '' ? $status : '(empty)'),
                $orderId
            ),
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Plugin-internal (called by PayPalCheckoutController + ReturnController)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create a one-off PayPal Order that ALSO saves the PayPal wallet in the vault
     * during purchase (always-vault-when-supported). Returns the full Orders v2
     * response — caller pulls `links[rel=approve].href` for the 302 redirect, and
     * `id` to stamp on intent.metadata['paypal_order_id'].
     *
     * The vault ride-along is a single call on the existing buyer redirect approval:
     * `payment_source.paypal.attributes.vault = { store_in_vault:ON_SUCCESS,
     * usage_type:MERCHANT }` tells PayPal to tokenise the wallet after the buyer
     * approves; `attributes.customer.merchant_customer_id` links the vault to this
     * merchant's customer. After capture, the return controller reads the vault id at
     * `payment_source.paypal.attributes.vault.id` (status VAULTED) and persists it.
     *
     * @param string $merchantCustomerId Stable per-customer key (customer email/uid)
     *        so repeat purchases attach to the same PayPal vault customer.
     *
     * @link https://developer.paypal.com/docs/checkout/save-payment-methods/purchase/paypal/
     */
    public function createOrder(
        string $intentUid,
        float $amountMajor,
        string $currency,
        string $description,
        string $returnUrl,
        string $cancelUrl,
        string $merchantCustomerId = '',
    ): array {
        $iso   = PayPalApi::assertSupportedCurrency($currency);
        $value = PayPalApi::formatAmount($amountMajor, $iso);

        $vaultAttributes = [
            // Tokenise the PayPal wallet after the buyer approves so the saved id
            // can be charged off-session on renewal (MERCHANT = merchant-initiated).
            'vault' => [
                'store_in_vault' => 'ON_SUCCESS',
                'usage_type'     => 'MERCHANT',
            ],
        ];
        if ($merchantCustomerId !== '') {
            $vaultAttributes['customer'] = ['merchant_customer_id' => $merchantCustomerId];
        }

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
            'payment_source' => [
                'paypal' => [
                    'attributes' => $vaultAttributes,
                    // Keep the buyer-present redirect approval flow (return/cancel urls)
                    // — experience_context replaces the deprecated top-level
                    // application_context when payment_source.paypal is present.
                    'experience_context' => [
                        'return_url'  => $returnUrl,
                        'cancel_url'  => $cancelUrl,
                        'user_action' => 'PAY_NOW',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Capture an approved Order. Called from the return controller after
     * customer approves at PayPal. Returns the full Capture response.
     */
    public function captureOrder(string $paypalOrderId): array
    {
        return $this->api->post("/v2/checkout/orders/{$paypalOrderId}/capture", []);
    }

    /**
     * Build the saved PaymentMethodDTO from a COMPLETED capture response, IFF PayPal
     * vaulted the wallet during purchase. Returns null when no reusable vault id was
     * issued (buyer declined, merchant lacks Reference-Transactions underwriting, etc.)
     * so the controller can persist the token when present and log-and-skip otherwise.
     *
     * Pure — no side effects. The vault id lives at
     * `payment_source.paypal.attributes.vault.id` with `.status === 'VAULTED'`.
     * autoCharge:true is set ONLY when a real vaulted id is present (never blindly).
     */
    public static function buildSavedPaymentMethod(array $captureResp): ?PaymentMethodDTO
    {
        $vault       = $captureResp['payment_source']['paypal']['attributes']['vault'] ?? [];
        $vaultId     = (string) ($vault['id'] ?? '');
        $vaultStatus = strtoupper((string) ($vault['status'] ?? ''));

        if ($vaultId === '' || $vaultStatus !== 'VAULTED') {
            return null;
        }

        $payerEmail = (string) ($captureResp['payment_source']['paypal']['email_address']
            ?? $captureResp['payer']['email_address']
            ?? '');

        return new PaymentMethodDTO(
            cardType:              'PayPal',
            last4:                 null,
            expirationDate:        null,
            email:                 $payerEmail ?: null,
            type:                  'paypal',
            metadata: [
                'paypal_vault_id' => $vaultId,   // ← mirror; consumed by autoCharge() on renewal
            ],
            remotePaymentMethodId: $vaultId,     // ← canonical reusable token
            // Real reusable vault token captured → this card IS off-session chargeable.
            autoCharge:            true,
        );
    }

    public function fetchOrder(string $paypalOrderId): array
    {
        return $this->api->get("/v2/checkout/orders/{$paypalOrderId}");
    }
}

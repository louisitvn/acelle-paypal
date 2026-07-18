<?php

namespace Acelle\Paypal\Services;

use Acelle\Paypal\Support\PayPalApi;
use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\DTO\CheckoutHandle;
use App\Cashier\DTO\DirectCheckout;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\Exceptions\UnsupportedCheckoutModeException;

/**
 * PayPal payment gateway (Orders API v2) — ONE-OFF hosted checkout only.
 *
 * Type: 'paypal'. Single capability: IntentGatewayInterface → buyer-present one-off
 * hosted checkout. The buyer is redirected to PayPal and gets both "Pay with PayPal"
 * (account) AND "Pay with Debit or Credit Card" (guest).
 *
 * NO off-session auto-charge and NO vaulting (deliberately removed — the vaulted
 * merchant-initiated charge could not be verified against live PayPal, and rather than
 * ship an unproven recurring lane the plugin is one-off only). Consequences:
 *   - A subscription plan paid through PayPal is renewed by the buyer paying each cycle
 *     manually — the host never auto-charges PayPal (it exposes no SupportsAutoChargeInterface).
 *   - A subscription-spec intent (a remote provider-managed subscription) is unsupported →
 *     getCheckoutUrl rejects it (fail loud) so the host offers another method rather than
 *     silently charging a one-off for a request that asked for recurring.
 *
 * Pure service — no DB writes, no handler dispatch. The controller layer
 * (PayPalCheckoutController + PayPalReturnController) owns the HTTP side effects and
 * dispatches CheckoutHandlerInterface on return.
 *
 * @link https://developer.paypal.com/docs/api/orders/v2/
 */
class PayPalGateway implements IntentGatewayInterface
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
    //  Plugin-internal — called by PayPalCheckoutController + ReturnController
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Create a one-off PayPal Order (intent=CAPTURE). Returns the full Orders v2 response —
     * the caller pulls `links[rel=approve].href` for the 302 redirect and `id` to stamp on
     * intent.metadata['paypal_order_id'].
     *
     * `experience_context` carries the buyer return/cancel URLs (it replaces the deprecated
     * top-level application_context when payment_source.paypal is present).
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
                'amount'       => [
                    'currency_code' => $iso,
                    'value'         => $value,
                ],
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

    /**
     * Capture an approved Order. Called from the return controller after the customer
     * approves at PayPal. Returns the full Capture response.
     */
    public function captureOrder(string $paypalOrderId): array
    {
        return $this->api->post("/v2/checkout/orders/{$paypalOrderId}/capture", []);
    }

    public function fetchOrder(string $paypalOrderId): array
    {
        return $this->api->get("/v2/checkout/orders/{$paypalOrderId}");
    }
}

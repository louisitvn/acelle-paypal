<?php

namespace Acelle\Paypal\Services;

use Acelle\Paypal\Support\PayPalApi;
use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\DTO\PaymentIntent;

/**
 * PayPal one-off payment gateway (Orders API v2).
 *
 * Type: 'paypal' — direct (NOT remote-subscription). Used when admin wants
 * to accept one-off PayPal payments for local-managed plans / custom invoices.
 * Customer redirects to PayPal hosted checkout — gets both "Pay with PayPal"
 * (account) AND "Pay with Debit or Credit Card" (guest) options.
 *
 * For recurring subscriptions managed by PayPal, use `PayPalSubscriptionGateway`
 * (type 'paypal-subscription') instead — that's the separate gateway in this
 * same plugin which speaks PayPal Subscriptions API v1.
 *
 * Pure service — no DB writes, no handler dispatch. Controller layer
 * (`PayPalCheckoutController` + `PayPalReturnController`) handles HTTP side
 * effects and dispatches `CheckoutHandlerInterface` on return.
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

    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl): string
    {
        return route('paypal.checkout', ['intent_uid' => $intent->uid])
            . '?return_url=' . urlencode($returnUrl);
    }

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
    //  Plugin-internal (called by PayPalCheckoutController + ReturnController)
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
            'application_context' => [
                'return_url'  => $returnUrl,
                'cancel_url'  => $cancelUrl,
                'user_action' => 'PAY_NOW',
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

    public function fetchOrder(string $paypalOrderId): array
    {
        return $this->api->get("/v2/checkout/orders/{$paypalOrderId}");
    }
}

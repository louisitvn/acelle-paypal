<?php

namespace Acelle\Paypal\Tests\Unit;

use Acelle\Paypal\Services\PayPalGateway;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\Payer;
use App\Cashier\DTO\SubscriptionSpec;
use App\Cashier\Exceptions\UnsupportedCheckoutModeException;
use Tests\TestCase;

// FakePayPalApi lives in tests/ and the plugin doesn't ship autoload-dev
// (would require host app to re-discover plugin autoload). require_once is
// the simplest fix — keeps the test helper out of src/ where it doesn't belong.
require_once __DIR__ . '/FakePayPalApi.php';

/**
 * Unit tests for PayPalGateway (ONE-OFF hosted checkout, Orders API v2).
 * PayPal is one-off / local charge ONLY — no subscription, no off-session auto-charge.
 *
 * Coverage:
 *   1. Currency guard + amount formatting (silent miscoding = wrong charge)
 *   2. Order create — reference_id round-trip identifier, endpoint, intent; capture + fetch endpoints
 *   3. getCheckoutUrl rejects a subscription intent (one-off only, fail loud)
 */
class PayPalGatewayTest extends TestCase
{
    private function makeGateway(FakePayPalApi $fake): PayPalGateway
    {
        $gw = new PayPalGateway('client-id', 'client-secret', 'sandbox');
        $ref = new \ReflectionClass($gw);
        $prop = $ref->getProperty('api');
        $prop->setAccessible(true);
        $prop->setValue($gw, $fake);
        return $gw;
    }

    // ── 1. Currency guard + amount formatting ────────────────────────────

    public function test_throws_on_unsupported_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported currency .XYZ./');
        $this->makeGateway(new FakePayPalApi())
            ->createOrder('intent-uid', 49.0, 'XYZ', 'desc', 'http://r', 'http://c');
    }

    public function test_supported_currencies_are_normalized_to_uppercase(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder('intent-uid', 10.0, 'usd', 'd', 'http://r', 'http://c');
        $this->assertSame('USD', $fake->lastBody['purchase_units'][0]['amount']['currency_code']);
    }

    public function test_formats_jpy_as_integer_string(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder('intent-uid', 49.5, 'JPY', 'd', 'http://r', 'http://c');
        $this->assertSame('50', $fake->lastBody['purchase_units'][0]['amount']['value']);
        $this->assertSame('JPY', $fake->lastBody['purchase_units'][0]['amount']['currency_code']);
    }

    public function test_formats_usd_as_two_decimal_string(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder('intent-uid', 49.0, 'USD', 'd', 'http://r', 'http://c');
        $this->assertSame('49.00', $fake->lastBody['purchase_units'][0]['amount']['value']);
    }

    public function test_formats_usd_fractional_to_two_decimal_with_no_trailing_zeros(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder('intent-uid', 49.555, 'EUR', 'd', 'http://r', 'http://c');
        $this->assertSame('49.56', $fake->lastBody['purchase_units'][0]['amount']['value']);
    }

    public function test_description_truncated_to_127_chars(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder('intent-uid', 1.0, 'USD', str_repeat('x', 200), 'http://r', 'http://c');
        $this->assertSame(127, strlen($fake->lastBody['purchase_units'][0]['description']));
    }

    // ── 2. Order create + capture/fetch endpoints ────────────────────────

    public function test_create_order_sends_reference_id_and_uses_orders_v2_endpoint(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder('local-intent-xyz', 10.0, 'USD', 'd', 'http://r', 'http://c');

        $this->assertSame('/v2/checkout/orders', $fake->lastPath);
        $this->assertSame('local-intent-xyz', $fake->lastBody['purchase_units'][0]['reference_id']);
        $this->assertSame('CAPTURE', $fake->lastBody['intent']);

        // return/cancel urls ride under payment_source.paypal.experience_context
        // (replaces the deprecated top-level application_context when payment_source.paypal is present).
        $ctx = $fake->lastBody['payment_source']['paypal']['experience_context'];
        $this->assertSame('http://r', $ctx['return_url']);
        $this->assertSame('http://c', $ctx['cancel_url']);
        $this->assertSame('PAY_NOW', $ctx['user_action']);
    }

    public function test_create_order_does_not_request_a_vault(): void
    {
        // One-off only: no wallet vaulting on the order (no attributes.vault block).
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder('intent-uid', 10.0, 'USD', 'd', 'http://r', 'http://c');
        $this->assertArrayNotHasKey('attributes', $fake->lastBody['payment_source']['paypal']);
    }

    public function test_capture_order_hits_correct_endpoint(): void
    {
        $fake = new FakePayPalApi(['status' => 'COMPLETED']);
        $this->makeGateway($fake)->captureOrder('PAYPAL-ORDER-1');
        $this->assertSame('POST', $fake->lastMethod);
        $this->assertSame('/v2/checkout/orders/PAYPAL-ORDER-1/capture', $fake->lastPath);
    }

    public function test_fetch_order_hits_correct_endpoint(): void
    {
        $fake = new FakePayPalApi(['id' => 'PAYPAL-ORDER-2', 'status' => 'CREATED']);
        $this->makeGateway($fake)->fetchOrder('PAYPAL-ORDER-2');
        $this->assertSame('GET', $fake->lastMethod);
        $this->assertSame('/v2/checkout/orders/PAYPAL-ORDER-2', $fake->lastPath);
    }

    // ── 3. One-off only — reject a subscription intent ───────────────────

    public function test_get_checkout_url_throws_on_subscription_intent(): void
    {
        $intent = new PaymentIntent(
            uid: 'intent-sub-1',
            amount: 49.0,
            currency: 'USD',
            description: 'Invoice',
            paymentGatewayId: 'gw',
            payer: new Payer(uid: 'payer-1', name: 'Buyer', email: 'b@x.y'),
            subscription: new SubscriptionSpec(remotePlanId: 'x'),
            metadata: [],
        );

        $this->expectException(UnsupportedCheckoutModeException::class);
        $this->makeGateway(new FakePayPalApi())
            ->getCheckoutUrl($intent, 'http://return');
    }
}

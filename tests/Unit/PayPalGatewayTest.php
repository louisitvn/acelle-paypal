<?php

namespace Acelle\Paypal\Tests\Unit;

use Acelle\Paypal\Services\PayPalGateway;
use Tests\TestCase;

// FakePayPalApi lives in tests/ and the plugin doesn't ship autoload-dev
// (would require host app to re-discover plugin autoload). require_once is
// the simplest fix — keeps the test helper out of src/ where it doesn't belong.
require_once __DIR__ . '/FakePayPalApi.php';

/**
 * Unit tests for PayPalGateway (one-off, Orders API v2).
 * Sibling: PayPalSubscriptionGatewayTest covers the Subscriptions v1 surface.
 *
 * Coverage:
 *   1. Currency guard + amount formatting (silent miscoding = wrong charge)
 *   2. Order create — reference_id round-trip identifier, endpoint, intent
 *   3. Capture endpoint
 *   4. IntentGatewayInterface basics (method title/info display)
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

    // ── 2. Order create + capture endpoint ───────────────────────────────

    public function test_create_order_sends_reference_id_and_uses_orders_v2_endpoint(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder('local-intent-xyz', 10.0, 'USD', 'd', 'http://r', 'http://c');

        $this->assertSame('/v2/checkout/orders', $fake->lastPath);
        $this->assertSame('local-intent-xyz', $fake->lastBody['purchase_units'][0]['reference_id']);
        $this->assertSame('CAPTURE', $fake->lastBody['intent']);
        $this->assertSame('http://r', $fake->lastBody['application_context']['return_url']);
        $this->assertSame('http://c', $fake->lastBody['application_context']['cancel_url']);
        $this->assertSame('PAY_NOW', $fake->lastBody['application_context']['user_action']);
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

    // ── 3. IntentGatewayInterface display ────────────────────────────────

    public function test_get_method_title_uses_billing_data_with_paypal_fallback(): void
    {
        $gw = $this->makeGateway(new FakePayPalApi());
        $this->assertSame('Visa', $gw->getMethodTitle(['card_type' => 'Visa']));
        $this->assertSame('PayPal', $gw->getMethodTitle([]));
    }

    public function test_get_method_info_prefers_email_over_last4(): void
    {
        $gw = $this->makeGateway(new FakePayPalApi());
        $this->assertSame('a@b.c', $gw->getMethodInfo(['payer_email' => 'a@b.c', 'last_4' => '1234']));
        $this->assertSame('**** **** **** 1234', $gw->getMethodInfo(['last_4' => '1234']));
        $this->assertSame('PayPal account', $gw->getMethodInfo([]));
    }
}

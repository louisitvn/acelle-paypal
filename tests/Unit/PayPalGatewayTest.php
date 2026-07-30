<?php

namespace Acelle\Paypal\Tests\Unit;

use Acelle\Paypal\Services\PayPalGateway;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\Payer;
use App\Cashier\DTO\PollableCheckout;
use App\Cashier\DTO\RemoteCheckoutSessionDTO;
use App\Cashier\DTO\SubscriptionSpec;
use Tests\TestCase;

// FakePayPalApi lives in tests/ and the plugin doesn't ship autoload-dev.
require_once __DIR__ . '/FakePayPalApi.php';

/**
 * Unit tests for PayPalGateway — merged one-off (Orders v2) + remote subscription (Subscriptions v1).
 *
 * Coverage:
 *   1. Currency guard + amount formatting (one-off)
 *   2. Order create / capture / fetch endpoints (one-off)
 *   3. Subscription checkout — create + pollable session + status mapping
 *   4. Read/manage — subscription DTO mapping, plan DTO mapping, cancel, empty list
 */
class PayPalGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // getCheckoutUrl's subscription branch wraps the host return URL in the plugin return route.
        \Illuminate\Support\Facades\Route::get('/t/paypal/return/{intent_uid}', fn () => '')->name('paypal.return');
        \Illuminate\Support\Facades\Route::get('/t/paypal/checkout/{intent_uid}', fn () => '')->name('paypal.checkout');
        app('router')->getRoutes()->refreshNameLookups();
    }

    private function makeGateway(FakePayPalApi $fake): PayPalGateway
    {
        $gw = new PayPalGateway('client-id', 'client-secret', 'sandbox');
        $ref = new \ReflectionClass($gw);
        $prop = $ref->getProperty('api');
        $prop->setAccessible(true);
        $prop->setValue($gw, $fake);
        return $gw;
    }

    private function intent(?SubscriptionSpec $sub, string $uid = 'i1', float $amount = 49.0): PaymentIntent
    {
        return new PaymentIntent(
            uid: $uid,
            amount: $amount,
            currency: 'USD',
            description: 'Invoice',
            paymentGatewayId: 'gw',
            payer: new Payer(uid: 'payer-1', name: 'Buyer', email: 'b@x.y'),
            subscription: $sub,
            metadata: [],
        );
    }

    // ── 1. Currency guard + amount formatting (one-off) ──────────────────

    public function test_throws_on_unsupported_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeGateway(new FakePayPalApi())->createOrder('i', 49.0, 'XYZ', 'd', 'http://r', 'http://c');
    }

    public function test_formats_jpy_as_integer_and_usd_as_two_decimals(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder('i', 49.5, 'JPY', 'd', 'http://r', 'http://c');
        $this->assertSame('50', $fake->lastBody['purchase_units'][0]['amount']['value']);
        $this->makeGateway($fake)->createOrder('i', 49.0, 'USD', 'd', 'http://r', 'http://c');
        $this->assertSame('49.00', $fake->lastBody['purchase_units'][0]['amount']['value']);
    }

    // ── 2. One-off order create / capture / fetch ────────────────────────

    public function test_create_order_uses_orders_v2_and_no_vault(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder('local-xyz', 10.0, 'USD', 'd', 'http://r', 'http://c');
        $this->assertSame('/v2/checkout/orders', $fake->lastPath);
        $this->assertSame('local-xyz', $fake->lastBody['purchase_units'][0]['reference_id']);
        $this->assertSame('CAPTURE', $fake->lastBody['intent']);
        $this->assertArrayNotHasKey('attributes', $fake->lastBody['payment_source']['paypal']);
    }

    public function test_capture_and_fetch_endpoints(): void
    {
        $fake = new FakePayPalApi(['status' => 'COMPLETED']);
        $gw = $this->makeGateway($fake);
        $gw->captureOrder('O1');
        $this->assertSame('/v2/checkout/orders/O1/capture', $fake->lastPath);
        $gw->fetchOrder('O2');
        $this->assertSame('/v2/checkout/orders/O2', $fake->lastPath);
        $this->assertSame('GET', $fake->lastMethod);
    }

    // ── 3. Subscription checkout (Subscriptions v1) ──────────────────────

    public function test_subscription_intent_creates_subscription_and_returns_pollable(): void
    {
        $fake = new FakePayPalApi([
            'id'    => 'I-SUB1',
            'status' => 'APPROVAL_PENDING',
            'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/approve/I-SUB1']],
        ]);
        $handle = $this->makeGateway($fake)->getCheckoutUrl(
            $this->intent(new SubscriptionSpec(remotePlanId: 'P-PLAN-123')),
            'https://app.test/return?intent=i1',
        );

        $this->assertInstanceOf(PollableCheckout::class, $handle);
        $this->assertSame('I-SUB1', $handle->sessionId);
        $this->assertSame('https://paypal.test/approve/I-SUB1', $handle->url);
        $this->assertNotNull($handle->expiresAt);
        $this->assertGreaterThan(time(), $handle->expiresAt);

        $this->assertSame('/v1/billing/subscriptions', $fake->lastPath);
        $this->assertSame('P-PLAN-123', $fake->lastBody['plan_id']);
        $this->assertSame('i1', $fake->lastBody['custom_id']);
        $this->assertSame('b@x.y', $fake->lastBody['subscriber']['email_address']);
        $this->assertStringContainsString('i1', $fake->lastBody['application_context']['return_url']);
        $this->assertSame('SUBSCRIBE_NOW', $fake->lastBody['application_context']['user_action']);
    }

    public function test_subscription_missing_plan_id_throws(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Billing Plan id/i');
        $this->makeGateway(new FakePayPalApi())->getCheckoutUrl(
            $this->intent(new SubscriptionSpec(remotePlanId: '')),
            'https://app.test/return',
        );
    }

    public function test_get_checkout_session_active_is_complete(): void
    {
        $fake = new FakePayPalApi(['id' => 'I-1', 'status' => 'ACTIVE', 'subscriber' => ['payer_id' => 'PAYER9']]);
        $dto = $this->makeGateway($fake)->getCheckoutSession('I-1');
        $this->assertTrue($dto->isComplete());
        $this->assertSame('I-1', $dto->remoteSubscriptionId);
        $this->assertSame('PAYER9', $dto->remoteCustomerId);
    }

    public function test_get_checkout_session_approval_pending_open_and_cancelled_expired(): void
    {
        $open = $this->makeGateway(new FakePayPalApi(['status' => 'APPROVAL_PENDING']))->getCheckoutSession('I-1');
        $this->assertSame(RemoteCheckoutSessionDTO::STATUS_OPEN, $open->status);

        $exp = $this->makeGateway(new FakePayPalApi(['status' => 'CANCELLED']))->getCheckoutSession('I-1');
        $this->assertTrue($exp->isExpired());
    }

    public function test_get_checkout_session_unknown_status_fails_loud(): void
    {
        $this->expectException(\LogicException::class);
        $this->makeGateway(new FakePayPalApi(['status' => 'WEIRD']))->getCheckoutSession('I-1');
    }

    // ── 4. Read / manage — DTO mapping, cancel, empty list ───────────────

    public function test_get_remote_subscription_maps_paypal_to_canonical(): void
    {
        $fake = new FakePayPalApi([
            'id'           => 'I-9',
            'status'       => 'ACTIVE',
            'plan_id'      => 'P-9',
            'subscriber'   => ['payer_id' => 'PX'],
            'billing_info' => [
                'next_billing_time' => '2026-09-01T00:00:00Z',
                // VERBATIM shape of PayPal Subscriptions v1 `last_payment` — {amount, time} and nothing
                // else. Captured live from an ACTIVE sub that had just collected $10. This fake once
                // carried a 'status' => 'COMPLETED' key that PayPal never sends (that is the Orders v2
                // CAPTURE vocabulary), which taught the mapper to read a field that does not exist and
                // hid the bug behind a green test. Do not add keys the vendor does not return.
                'last_payment'      => ['amount' => ['value' => '10.00', 'currency_code' => 'USD'], 'time' => '2026-08-01T00:00:00Z'],
            ],
        ]);
        $dto = $this->makeGateway($fake)->getRemoteSubscription('I-9');
        $this->assertSame('active', $dto->status);
        $this->assertSame('P-9', $dto->remotePlanId);
        $this->assertSame(10.0, $dto->latestInvoiceAmount);
        $this->assertTrue($dto->isActive());

        // PayPal states no status for the last payment, so the DTO must say so rather than infer a
        // cheerful 'paid' — the admin badge simply does not render. Per-transaction status is
        // available authoritatively via getRemoteInvoices().
        $this->assertNull($dto->latestInvoiceStatus);
    }

    public function test_get_remote_subscriptions_is_empty_no_list_endpoint(): void
    {
        $out = $this->makeGateway(new FakePayPalApi())->getRemoteSubscriptions();
        $this->assertSame([], $out['data']);
        $this->assertFalse($out['has_more']);
    }

    public function test_cancel_subscription_hits_cancel_endpoint(): void
    {
        $fake = new FakePayPalApi();
        $this->makeGateway($fake)->cancelRemoteSubscription('I-5');
        $this->assertSame('POST', $fake->lastMethod);
        $this->assertSame('/v1/billing/subscriptions/I-5/cancel', $fake->lastPath);
    }

    public function test_plan_maps_price_interval_and_trial(): void
    {
        $fake = new FakePayPalApi([
            'id'     => 'P-7',
            'name'   => 'Pro Monthly',
            'status' => 'ACTIVE',
            'billing_cycles' => [
                ['tenure_type' => 'TRIAL', 'frequency' => ['interval_unit' => 'DAY', 'interval_count' => 14], 'total_cycles' => 1],
                ['tenure_type' => 'REGULAR', 'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                 'pricing_scheme' => ['fixed_price' => ['value' => '29.00', 'currency_code' => 'USD']]],
            ],
        ]);
        $plan = $this->makeGateway($fake)->getRemotePlan('P-7');
        $this->assertSame('P-7', $plan->id);
        $this->assertSame('Pro Monthly', $plan->name);
        $this->assertSame(29.0, $plan->price);
        $this->assertSame('USD', $plan->currency);
        $this->assertSame('month', $plan->intervalUnit);
        $this->assertSame(1, $plan->intervalCount);
        $this->assertSame(14, $plan->trialDays);
    }

    // ── 5. Capabilities PayPal must NOT claim ────────────────────────────

    /**
     * Publishing a catalog does not imply being able to MOVE a subscription inside it. PayPal's revise
     * cannot switch a plan AND collect the difference in one call — a PayPal-funded sub keeps billing
     * the old plan unless the subscriber re-consents, and a card-funded one only changes price at the
     * next cycle. So the gateway declines the capability and the host refuses the plan change up front,
     * rather than the gateway faking a switch that did not happen.
     */
    public function test_does_not_claim_the_plan_change_capability(): void
    {
        $gw = $this->makeGateway(new FakePayPalApi());

        $this->assertNotInstanceOf(\App\Cashier\Contracts\SupportsRemotePlanChange::class, $gw);
        $this->assertInstanceOf(\App\Cashier\Contracts\SupportsRemoteCatalogInterface::class, $gw);
    }

    // ── 6. Catalog surface PayPal cannot back — must fail loud, not fake it ──

    /**
     * Each of these is a question PayPal's API cannot answer. A null/empty "degraded" answer would be
     * indistinguishable from a real one (e.g. null failure = "nothing wrong"), so the gateway refuses.
     */
    public function test_unsupported_catalog_operations_throw_instead_of_degrading(): void
    {
        $gw = $this->makeGateway(new FakePayPalApi());

        foreach ([
            'getRemoteInvoiceFailure'          => fn () => $gw->getRemoteInvoiceFailure('TXN-1'),
            'getCardUpdateUrl'                 => fn () => $gw->getCardUpdateUrl('PAYER-1', 'https://a/r', 'https://a/c'),
            'resolveStoredPaymentMethod'       => fn () => $gw->resolveStoredPaymentMethod('sess-1'),
            'setRemoteSubscriptionPaymentMethod' => fn () => $gw->setRemoteSubscriptionPaymentMethod('I-9', 'PM-1'),
        ] as $name => $call) {
            try {
                $call();
                $this->fail("{$name}() must throw — PayPal cannot support it.");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('PayPal', $e->getMessage(), "{$name}() must say why");
            }
        }
    }

}

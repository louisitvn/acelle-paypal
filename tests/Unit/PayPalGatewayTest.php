<?php

namespace Acelle\Paypal\Tests\Unit;

use Acelle\Paypal\Services\PayPalGateway;
use Acelle\Paypal\Support\PayPalApi;
use App\Cashier\DTO\RemotePlanDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use Tests\TestCase;

/**
 * Unit tests for PayPalGateway — focus on:
 *   1. Currency / amount payload shape (silent miscoding would charge wrong amount)
 *   2. Status normalization (PayPal → local enum)
 *   3. Error-detail extraction (customer-facing reason string)
 *   4. Endpoint paths (URL contract with PayPal)
 *
 * Uses a fake PayPalApi double via reflection — no live HTTP. Keeps tests fast
 * and CI-stable.
 */
class PayPalGatewayTest extends TestCase
{
    private function makeGatewayWithFakeApi(FakePayPalApi $fake): PayPalGateway
    {
        $gw = new PayPalGateway('client-id', 'client-secret', 'sandbox');
        // Inject the fake via reflection — gateway's $api is private.
        $ref = new \ReflectionClass($gw);
        $prop = $ref->getProperty('api');
        $prop->setAccessible(true);
        $prop->setValue($gw, $fake);
        return $gw;
    }

    // ─── 1. Currency guard ───────────────────────────────────────────────

    public function test_throws_on_unsupported_currency(): void
    {
        $gw = $this->makeGatewayWithFakeApi(new FakePayPalApi());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported currency .XYZ./');

        $gw->createOrder('intent-uid', 49.0, 'XYZ', 'desc', 'http://r', 'http://c');
    }

    // ─── 2. Amount formatting (JPY zero-decimal vs USD 2-decimal) ────────

    public function test_formats_jpy_as_integer_string(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $gw   = $this->makeGatewayWithFakeApi($fake);

        $gw->createOrder('intent-uid', 49.5, 'JPY', 'd', 'http://r', 'http://c');

        $sentBody = $fake->lastBody;
        $this->assertSame('50', $sentBody['purchase_units'][0]['amount']['value'], 'JPY should be integer string with no decimal');
        $this->assertSame('JPY', $sentBody['purchase_units'][0]['amount']['currency_code']);
    }

    public function test_formats_usd_as_two_decimal_string(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $gw   = $this->makeGatewayWithFakeApi($fake);

        $gw->createOrder('intent-uid', 49.0, 'USD', 'd', 'http://r', 'http://c');

        $this->assertSame('49.00', $fake->lastBody['purchase_units'][0]['amount']['value']);
        $this->assertSame('USD', $fake->lastBody['purchase_units'][0]['amount']['currency_code']);
    }

    // ─── 3. Order create stamps intent uid + correct endpoint ────────────

    public function test_create_order_sends_reference_id_and_uses_orders_v2_endpoint(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $gw   = $this->makeGatewayWithFakeApi($fake);

        $gw->createOrder('local-intent-xyz', 10.0, 'USD', 'd', 'http://r', 'http://c');

        $this->assertSame('/v2/checkout/orders', $fake->lastPath);
        $this->assertSame('local-intent-xyz', $fake->lastBody['purchase_units'][0]['reference_id']);
        $this->assertSame('CAPTURE', $fake->lastBody['intent']);
    }

    // ─── 4. Subscription create uses custom_id = intent_uid ──────────────

    public function test_subscription_create_uses_intent_uid_as_custom_id(): void
    {
        $fake = new FakePayPalApi(['id' => 'I-X', 'status' => 'APPROVAL_PENDING', 'links' => []]);
        $gw   = $this->makeGatewayWithFakeApi($fake);

        $gw->createSubscriptionViaCheckout(
            intentUid:     'local-intent-abc',
            remotePlanId:  'P-PLAN-1',
            customerEmail: 'a@b.c',
            returnUrl:     'http://r',
            cancelUrl:     'http://c',
        );

        $this->assertSame('/v1/billing/subscriptions', $fake->lastPath);
        $this->assertSame('local-intent-abc', $fake->lastBody['custom_id']);
        $this->assertSame('P-PLAN-1', $fake->lastBody['plan_id']);
    }

    // ─── 5. Status normalization (PayPal ACTIVE/CANCELLED/SUSPENDED → local) ─

    public function test_subscription_to_dto_normalizes_status(): void
    {
        $cases = [
            'ACTIVE'           => 'active',
            'CANCELLED'        => 'canceled',
            'SUSPENDED'        => 'paused',
            'EXPIRED'          => 'canceled',
            'APPROVAL_PENDING' => 'incomplete',
            'APPROVED'         => 'incomplete',
        ];
        foreach ($cases as $paypalStatus => $expectedLocal) {
            $fake = new FakePayPalApi([
                'id'        => 'I-1',
                'status'    => $paypalStatus,
                'plan_id'   => 'P-1',
                'subscriber'=> ['payer_id' => 'PID', 'email_address' => 'x@y.z'],
            ]);
            $gw  = $this->makeGatewayWithFakeApi($fake);
            $dto = $gw->getRemoteSubscription('I-1');

            $this->assertInstanceOf(RemoteSubscriptionDTO::class, $dto);
            $this->assertSame(
                $expectedLocal, $dto->status,
                "PayPal {$paypalStatus} should map to local {$expectedLocal}"
            );
        }
    }

    // ─── 6. cancelRemoteSubscription hits the right endpoint ─────────────

    public function test_cancel_remote_subscription_posts_to_cancel_endpoint(): void
    {
        $fake = new FakePayPalApi([]);  // 204-style empty body OK
        $gw   = $this->makeGatewayWithFakeApi($fake);

        $gw->cancelRemoteSubscription('I-CANCEL-ME');

        $this->assertSame('/v1/billing/subscriptions/I-CANCEL-ME/cancel', $fake->lastPath);
        $this->assertSame('POST', $fake->lastMethod);
    }

    // ─── 7. createSubscription (interface method) throws — hosted-only ───

    public function test_create_subscription_interface_method_throws(): void
    {
        $gw = $this->makeGatewayWithFakeApi(new FakePayPalApi());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/hosted checkout/');

        $intent = new \App\Cashier\DTO\PaymentIntent(
            uid: 'uid', amount: 49.0, currency: 'USD', description: 'd',
            paymentGatewayId: 'gw', payer: new \App\Cashier\DTO\Payer(
                uid: 'cid', name: 'n', email: 'e@x.y',
            ),
            subscription: null,
        );
        $gw->createSubscription($intent, []);
    }
}

/**
 * Test double for PayPalApi — captures last call's method/path/body so tests
 * can assert payload shape without round-tripping HTTP.
 */
class FakePayPalApi extends PayPalApi
{
    public ?string $lastMethod = null;
    public ?string $lastPath   = null;
    public array $lastBody     = [];
    public array $lastQuery    = [];
    private array $response;

    public function __construct(array $response = [])
    {
        // Skip parent constructor — we don't want a real Guzzle client.
        $this->response = $response;
    }

    public function get(string $path, array $query = []): array
    {
        $this->lastMethod = 'GET';
        $this->lastPath   = $path;
        $this->lastQuery  = $query;
        return $this->response;
    }

    public function post(string $path, array $body): array
    {
        $this->lastMethod = 'POST';
        $this->lastPath   = $path;
        $this->lastBody   = $body;
        return $this->response;
    }

    public function patch(string $path, array $body): array
    {
        $this->lastMethod = 'PATCH';
        $this->lastPath   = $path;
        $this->lastBody   = $body;
        return $this->response;
    }
}

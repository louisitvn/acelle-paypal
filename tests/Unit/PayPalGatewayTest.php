<?php

namespace Acelle\Paypal\Tests\Unit;

use Acelle\Paypal\Services\PayPalGateway;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\PaymentMethodDTO;
use App\Cashier\DTO\PaymentResult;
use App\Cashier\DTO\Payer;
use App\Cashier\DTO\SubscriptionSpec;
use App\Cashier\Exceptions\UnsupportedCheckoutModeException;
use Tests\TestCase;

// FakePayPalApi lives in tests/ and the plugin doesn't ship autoload-dev
// (would require host app to re-discover plugin autoload). require_once is
// the simplest fix — keeps the test helper out of src/ where it doesn't belong.
require_once __DIR__ . '/FakePayPalApi.php';

/**
 * Unit tests for PayPalGateway (one-off, Orders API v2).
 * PayPal is one-off / local charge ONLY — there is no subscription gateway.
 *
 * Coverage:
 *   1. Currency guard + amount formatting (silent miscoding = wrong charge)
 *   2. Order create — reference_id round-trip identifier, endpoint, intent
 *   3. Capture endpoint
 *   4. getCheckoutUrl rejects a subscription intent (one-off only, fail loud)
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

        // return/cancel urls now ride under payment_source.paypal.experience_context
        // (required once payment_source.paypal is present for the vault ride-along).
        $ctx = $fake->lastBody['payment_source']['paypal']['experience_context'];
        $this->assertSame('http://r', $ctx['return_url']);
        $this->assertSame('http://c', $ctx['cancel_url']);
        $this->assertSame('PAY_NOW', $ctx['user_action']);
    }

    // ── Vault-during-purchase attributes on createOrder ───────────────────

    public function test_create_order_requests_vault_on_success_during_purchase(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder(
            'intent-uid', 10.0, 'USD', 'd', 'http://r', 'http://c', 'cust-9'
        );

        $vault = $fake->lastBody['payment_source']['paypal']['attributes']['vault'];
        $this->assertSame('ON_SUCCESS', $vault['store_in_vault']);
        $this->assertSame('MERCHANT', $vault['usage_type']);
        $this->assertSame(
            'cust-9',
            $fake->lastBody['payment_source']['paypal']['attributes']['customer']['merchant_customer_id']
        );
    }

    public function test_create_order_omits_customer_block_when_no_merchant_customer_id(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $this->makeGateway($fake)->createOrder('intent-uid', 10.0, 'USD', 'd', 'http://r', 'http://c');

        $attrs = $fake->lastBody['payment_source']['paypal']['attributes'];
        $this->assertArrayHasKey('vault', $attrs);
        $this->assertArrayNotHasKey('customer', $attrs,
            'No merchant_customer_id → no customer block (avoid sending an empty id)');
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

    // ── 4. autoCharge — off-session vault-token renewal ──────────────────

    private function renewalIntent(string $currency = 'USD', float $amount = 20.0): PaymentIntent
    {
        return new PaymentIntent(
            uid: 'intent-renew-1',
            amount: $amount,
            currency: $currency,
            description: 'Subscription renewal',
            paymentGatewayId: 'gw',
            payer: new Payer(uid: 'payer-1', name: 'Buyer', email: 'b@x.y'),
            subscription: null,
            metadata: [],
        );
    }

    private function savedPaypalWallet(string $vaultId = 'VAULT-123'): PaymentMethodDTO
    {
        // Mirror the DTO the return controller persisted: canonical vault id +
        // metadata mirror, auto_charge on. Round-trip through fromArray so the
        // read path (remotePaymentMethodId / metadata['paypal_vault_id']) is exercised.
        return PaymentMethodDTO::fromArray([
            'type'                     => 'paypal',
            'card_type'                => 'PayPal',
            'email'                    => 'b@x.y',
            'remote_payment_method_id' => $vaultId,
            'paypal_vault_id'          => $vaultId,
            'auto_charge'              => true,
        ]);
    }

    public function test_auto_charge_success_on_completed_order(): void
    {
        $fake = new FakePayPalApi(['id' => 'ORDER-OK', 'status' => 'COMPLETED']);
        $result = $this->makeGateway($fake)->autoCharge($this->renewalIntent(), $this->savedPaypalWallet());

        $this->assertSame(PaymentResult::STATUS_SUCCESS, $result->status);
        $this->assertSame('ORDER-OK', $result->remoteReferenceId);
        $this->assertSame('ORDER-OK', $result->metadata['paypal_order_id']);

        // MIT body: vault_id + REQUIRED stored_credential + idempotency header.
        $this->assertSame('/v2/checkout/orders', $fake->lastPath);
        $this->assertSame('CAPTURE', $fake->lastBody['intent']);
        $src = $fake->lastBody['payment_source']['paypal'];
        $this->assertSame('VAULT-123', $src['vault_id']);
        $this->assertSame('MERCHANT', $src['stored_credential']['payment_initiator']);
        $this->assertSame('SUBSEQUENT', $src['stored_credential']['usage']);
        $this->assertSame('RECURRING_PREPAID', $src['stored_credential']['usage_pattern']);
        $this->assertSame('paypal-autocharge-intent-renew-1', $fake->lastHeaders['PayPal-Request-Id']);
    }

    public function test_auto_charge_hard_decline_maps_to_failed(): void
    {
        $fake = new FakePayPalApi(['id' => 'ORDER-D', 'status' => 'DECLINED']);
        $result = $this->makeGateway($fake)->autoCharge($this->renewalIntent(), $this->savedPaypalWallet());

        $this->assertSame(PaymentResult::STATUS_FAILED, $result->status);
        $this->assertSame('ORDER-D', $result->remoteReferenceId);
        $this->assertStringContainsString('DECLINED', (string) $result->error);
    }

    public function test_auto_charge_payer_action_required_maps_to_requires_auth(): void
    {
        // Unattended cron cannot satisfy a step-up → requiresAuth (host re-prompts the
        // customer), NEVER a silent retry loop that re-declines forever.
        $fake = new FakePayPalApi(['id' => 'ORDER-A', 'status' => 'PAYER_ACTION_REQUIRED']);
        $result = $this->makeGateway($fake)->autoCharge($this->renewalIntent(), $this->savedPaypalWallet());

        $this->assertSame(PaymentResult::STATUS_REQUIRES_ACTION, $result->status);
        $this->assertSame('ORDER-A', $result->remoteReferenceId);
    }

    public function test_auto_charge_api_exception_maps_to_failed(): void
    {
        $fake = new FakePayPalApi();
        $fake->throwOnNextCall(new \RuntimeException('PayPal POST /v2/checkout/orders failed: [422] card declined'));
        $result = $this->makeGateway($fake)->autoCharge($this->renewalIntent(), $this->savedPaypalWallet());

        $this->assertSame(PaymentResult::STATUS_FAILED, $result->status);
        $this->assertStringContainsString('card declined', (string) $result->error);
    }

    public function test_auto_charge_unknown_status_fails_loud_not_success(): void
    {
        $fake = new FakePayPalApi(['id' => 'ORDER-X', 'status' => 'SOMETHING_NEW']);
        $result = $this->makeGateway($fake)->autoCharge($this->renewalIntent(), $this->savedPaypalWallet());

        $this->assertSame(PaymentResult::STATUS_FAILED, $result->status,
            'Unknown vendor status must never render as success');
        $this->assertStringContainsString('SOMETHING_NEW', (string) $result->error);
    }

    public function test_auto_charge_missing_vault_id_fails_without_calling_api(): void
    {
        $fake = new FakePayPalApi(['id' => 'x', 'status' => 'COMPLETED']);
        // Saved method with NO reusable token (autoCharge:false path).
        $pm = PaymentMethodDTO::fromArray(['type' => 'paypal', 'auto_charge' => false]);
        $result = $this->makeGateway($fake)->autoCharge($this->renewalIntent(), $pm);

        $this->assertSame(PaymentResult::STATUS_FAILED, $result->status);
        $this->assertStringContainsString('missing paypal_vault_id', (string) $result->error);
        $this->assertNull($fake->lastPath, 'Must not hit the PayPal API when there is no vault id');
    }

    public function test_auto_charge_unsupported_currency_fails_without_calling_api(): void
    {
        $fake = new FakePayPalApi(['id' => 'x', 'status' => 'COMPLETED']);
        $result = $this->makeGateway($fake)->autoCharge($this->renewalIntent('XYZ'), $this->savedPaypalWallet());

        $this->assertSame(PaymentResult::STATUS_FAILED, $result->status);
        $this->assertStringContainsString('unsupported currency', (string) $result->error);
        $this->assertNull($fake->lastPath);
    }

    // ── 5. Vault-capture — buildSavedPaymentMethod from capture response ──

    public function test_build_saved_payment_method_captures_vault_token(): void
    {
        $captureResp = [
            'id'     => 'ORDER-1',
            'status' => 'COMPLETED',
            'payment_source' => ['paypal' => [
                'email_address' => 'buyer@paypal.test',
                'attributes' => ['vault' => [
                    'id'     => 'VAULT-XYZ',
                    'status' => 'VAULTED',
                ]],
            ]],
        ];

        $pm = PayPalGateway::buildSavedPaymentMethod($captureResp);

        $this->assertInstanceOf(PaymentMethodDTO::class, $pm);
        $this->assertTrue($pm->autoCharge, 'Real vault token captured → auto_charge on');
        $this->assertSame('VAULT-XYZ', $pm->remotePaymentMethodId);
        $this->assertSame('VAULT-XYZ', $pm->metadata['paypal_vault_id']);
        $this->assertSame('paypal', $pm->type);
        $this->assertSame('buyer@paypal.test', $pm->email);

        // Round-trip: the persisted bag must feed autoCharge()'s read path.
        $reloaded = PaymentMethodDTO::fromArray($pm->toArray());
        $this->assertTrue($reloaded->autoCharge);
        $this->assertSame('VAULT-XYZ', $reloaded->remotePaymentMethodId);
    }

    public function test_build_saved_payment_method_returns_null_when_no_vault_issued(): void
    {
        // COMPLETED capture but PayPal did not vault (no reusable token) → null so the
        // controller logs-and-skips; the method is never flagged auto-chargeable.
        $captureResp = [
            'id'     => 'ORDER-2',
            'status' => 'COMPLETED',
            'payment_source' => ['paypal' => ['email_address' => 'b@x.y']],
        ];

        $this->assertNull(PayPalGateway::buildSavedPaymentMethod($captureResp));
    }

    public function test_build_saved_payment_method_returns_null_when_vault_not_vaulted_status(): void
    {
        // Vault id present but status not VAULTED (e.g. CREATED/PENDING) → not usable yet.
        $captureResp = [
            'payment_source' => ['paypal' => ['attributes' => ['vault' => [
                'id'     => 'VAULT-PENDING',
                'status' => 'CREATED',
            ]]]],
        ];

        $this->assertNull(PayPalGateway::buildSavedPaymentMethod($captureResp));
    }
}

<?php

namespace Acelle\Paypal\Tests\Unit;

use Acelle\Paypal\Services\PayPalSubscriptionGateway;
use App\Cashier\DTO\BillingOrigin;
use App\Cashier\DTO\RemoteInvoiceDTO;
use App\Cashier\DTO\RemotePaymentMethodDTO;
use App\Cashier\DTO\RemotePlanDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use Tests\TestCase;

require_once __DIR__ . '/FakePayPalApi.php';

/**
 * Unit tests for PayPalSubscriptionGateway (recurring, Billing Subscriptions v1).
 * Sibling: PayPalGatewayTest covers the Orders v2 one-off surface.
 *
 * Coverage:
 *   1. Subscription create — custom_id round-trip, plan_id, endpoint
 *   2. Status / DTO mapping (PayPal JSON → local DTO)
 *   3. Plan mapping (REGULAR/TRIAL cycle pick, interval normalization, trial days)
 *   4. Transaction → RemoteInvoiceDTO (status, oldest-first, cursor, RECURRING origin)
 *   5. Cancel / Resume / Update / Extract / GetRemotePaymentMethod
 *   6. Pagination + empty-page fallback
 *   7. Pull-only contract (webhook stubs return inert values)
 *   8. SupportsSubscriptionInterface::createSubscription throws (hosted-only)
 */
class PayPalSubscriptionGatewayTest extends TestCase
{
    private function makeGateway(FakePayPalApi $fake): PayPalSubscriptionGateway
    {
        $gw = new PayPalSubscriptionGateway('client-id', 'client-secret', 'sandbox');
        $ref = new \ReflectionClass($gw);
        $prop = $ref->getProperty('api');
        $prop->setAccessible(true);
        $prop->setValue($gw, $fake);
        return $gw;
    }

    // ── 1. Subscription create — custom_id round-trip ────────────────────

    public function test_subscription_create_uses_intent_uid_as_custom_id(): void
    {
        $fake = new FakePayPalApi(['id' => 'I-X', 'status' => 'APPROVAL_PENDING', 'links' => []]);
        $this->makeGateway($fake)->createSubscriptionViaCheckout(
            intentUid:     'local-intent-abc',
            remotePlanId:  'P-PLAN-1',
            customerEmail: 'a@b.c',
            returnUrl:     'http://r',
            cancelUrl:     'http://c',
        );

        $this->assertSame('/v1/billing/subscriptions', $fake->lastPath);
        $this->assertSame('local-intent-abc', $fake->lastBody['custom_id']);
        $this->assertSame('P-PLAN-1', $fake->lastBody['plan_id']);
        $this->assertSame('a@b.c', $fake->lastBody['subscriber']['email_address']);
        $this->assertSame('SUBSCRIBE_NOW', $fake->lastBody['application_context']['user_action']);
    }

    public function test_subscription_create_throws_on_missing_remote_plan_id(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/remote_plan_id/');
        $this->makeGateway(new FakePayPalApi())
            ->createSubscriptionViaCheckout('uid', '', 'a@b.c', 'http://r', 'http://c');
    }

    public function test_create_subscription_interface_method_throws(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/hosted checkout/');

        $intent = new \App\Cashier\DTO\PaymentIntent(
            uid: 'uid', amount: 49.0, currency: 'USD', description: 'd',
            paymentGatewayId: 'gw', payer: new \App\Cashier\DTO\Payer(
                uid: 'cid', name: 'n', email: 'e@x.y',
            ),
            subscription: null,
        );
        $this->makeGateway(new FakePayPalApi())->createSubscription($intent, []);
    }

    // ── 2. subscriptionToDto — status + field mapping ────────────────────

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
                'id'         => 'I-1',
                'status'     => $paypalStatus,
                'plan_id'    => 'P-1',
                'subscriber' => ['payer_id' => 'PID', 'email_address' => 'x@y.z'],
            ]);
            $dto = $this->makeGateway($fake)->getRemoteSubscription('I-1');
            $this->assertSame($expectedLocal, $dto->status,
                "PayPal {$paypalStatus} should map to local {$expectedLocal}");
        }
    }

    public function test_subscription_to_dto_maps_billing_info_fields(): void
    {
        $fake = new FakePayPalApi([
            'id'         => 'I-2',
            'status'     => 'ACTIVE',
            'plan_id'    => 'P-2',
            'subscriber' => ['payer_id' => 'PID-X', 'email_address' => 'a@b.c'],
            'billing_info' => [
                'next_billing_time' => '2026-06-01T00:00:00Z',
                'last_payment' => [
                    'amount' => ['value' => '49.00', 'currency_code' => 'USD'],
                    'time'   => '2026-05-01T00:00:00Z',
                ],
            ],
        ]);
        $dto = $this->makeGateway($fake)->getRemoteSubscription('I-2');

        $this->assertSame('I-2', $dto->id);
        $this->assertSame('P-2', $dto->remotePlanId);
        $this->assertSame('PID-X', $dto->remoteCustomerId);
        $this->assertSame('2026-06-01', $dto->currentPeriodEnd->toDateString());
        $this->assertSame('2026-05-01', $dto->currentPeriodStart->toDateString());
        $this->assertSame(49.0, $dto->latestInvoiceAmount);
        $this->assertSame('paid', $dto->latestInvoiceStatus);
    }

    public function test_subscription_to_dto_sets_canceled_at_only_when_canceled(): void
    {
        $fakeActive = new FakePayPalApi([
            'id' => 'I-3', 'status' => 'ACTIVE', 'plan_id' => 'P',
            'status_update_time' => '2026-04-01T00:00:00Z',
        ]);
        $this->assertNull($this->makeGateway($fakeActive)->getRemoteSubscription('I-3')->canceledAt);

        $fakeCancelled = new FakePayPalApi([
            'id' => 'I-4', 'status' => 'CANCELLED', 'plan_id' => 'P',
            'status_update_time' => '2026-04-01T00:00:00Z',
        ]);
        $this->assertSame('2026-04-01',
            $this->makeGateway($fakeCancelled)->getRemoteSubscription('I-4')->canceledAt->toDateString());
    }

    // ── 3. planToDto — REGULAR/TRIAL pick, intervals, trial days ─────────

    public function test_plan_to_dto_picks_regular_cycle_over_trial(): void
    {
        $fake = new FakePayPalApi([
            'id' => 'P-A', 'name' => 'Pro Plan', 'status' => 'ACTIVE',
            'billing_cycles' => [
                ['tenure_type' => 'TRIAL', 'frequency' => ['interval_unit' => 'DAY', 'interval_count' => 7],
                 'pricing_scheme' => ['fixed_price' => ['value' => '0', 'currency_code' => 'USD']]],
                ['tenure_type' => 'REGULAR', 'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                 'pricing_scheme' => ['fixed_price' => ['value' => '99.00', 'currency_code' => 'USD']]],
            ],
        ]);
        $dto = $this->makeGateway($fake)->getRemotePlan('P-A');

        $this->assertSame('Pro Plan', $dto->name);
        $this->assertSame(99.0, $dto->price);
        $this->assertSame('USD', $dto->currency);
        $this->assertSame(1, $dto->intervalCount);
        $this->assertSame('month', $dto->intervalUnit);
        $this->assertSame('active', $dto->status);
        $this->assertSame(7, $dto->trialDays);
    }

    public function test_plan_to_dto_normalizes_interval_units(): void
    {
        $cases = [
            'DAY'   => 'day',
            'WEEK'  => 'week',
            'MONTH' => 'month',
            'YEAR'  => 'year',
            'UNKNOWN' => 'month',
        ];
        foreach ($cases as $paypalUnit => $expectedLocal) {
            $fake = new FakePayPalApi([
                'id' => 'P-' . $paypalUnit, 'name' => 'X', 'status' => 'ACTIVE',
                'billing_cycles' => [[
                    'tenure_type' => 'REGULAR',
                    'frequency'   => ['interval_unit' => $paypalUnit, 'interval_count' => 3],
                    'pricing_scheme' => ['fixed_price' => ['value' => '10', 'currency_code' => 'USD']],
                ]],
            ]);
            $dto = $this->makeGateway($fake)->getRemotePlan('P-' . $paypalUnit);
            $this->assertSame($expectedLocal, $dto->intervalUnit);
            $this->assertSame(3, $dto->intervalCount);
        }
    }

    public function test_plan_to_dto_calculates_trial_days_from_unit(): void
    {
        foreach ([['DAY', 2, 2], ['WEEK', 2, 14], ['MONTH', 1, 30], ['YEAR', 1, 365]] as [$unit, $count, $expectedDays]) {
            $fake = new FakePayPalApi([
                'id' => 'P', 'name' => 'X', 'status' => 'ACTIVE',
                'billing_cycles' => [
                    ['tenure_type' => 'TRIAL',
                     'frequency' => ['interval_unit' => $unit, 'interval_count' => $count],
                     'pricing_scheme' => ['fixed_price' => ['value' => '0', 'currency_code' => 'USD']]],
                    ['tenure_type' => 'REGULAR',
                     'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                     'pricing_scheme' => ['fixed_price' => ['value' => '10', 'currency_code' => 'USD']]],
                ],
            ]);
            $dto = $this->makeGateway($fake)->getRemotePlan('P');
            $this->assertSame($expectedDays, $dto->trialDays);
        }
    }

    // ── 4. transactionToInvoiceDto — status, sort, cursor, origin ────────

    public function test_get_remote_invoices_maps_transaction_statuses(): void
    {
        $cases = [
            'COMPLETED'          => 'paid',
            'DECLINED'           => 'failed',
            'FAILED'             => 'failed',
            'REFUNDED'           => 'refunded',
            'PARTIALLY_REFUNDED' => 'refunded',
            'PENDING'            => 'open',
        ];
        foreach ($cases as $paypalStatus => $expectedLocal) {
            $fake = new FakePayPalApi([
                'transactions' => [[
                    'id' => 'TX-' . $paypalStatus, 'status' => $paypalStatus,
                    'time' => '2026-05-01T00:00:00Z',
                    'amount_with_breakdown' => ['gross_amount' => ['value' => '49.00', 'currency_code' => 'USD']],
                ]],
            ]);
            $page = $this->makeGateway($fake)->getRemoteInvoices('I-SUB');
            $this->assertCount(1, $page['data']);
            $this->assertSame($expectedLocal, $page['data'][0]->status);
        }
    }

    public function test_get_remote_invoices_sorts_oldest_first(): void
    {
        $fake = new FakePayPalApi([
            'transactions' => [
                ['id' => 'TX-NEW', 'status' => 'COMPLETED', 'time' => '2026-05-10T00:00:00Z',
                 'amount_with_breakdown' => ['gross_amount' => ['value' => '49', 'currency_code' => 'USD']]],
                ['id' => 'TX-OLD', 'status' => 'COMPLETED', 'time' => '2026-04-10T00:00:00Z',
                 'amount_with_breakdown' => ['gross_amount' => ['value' => '49', 'currency_code' => 'USD']]],
                ['id' => 'TX-MID', 'status' => 'COMPLETED', 'time' => '2026-04-25T00:00:00Z',
                 'amount_with_breakdown' => ['gross_amount' => ['value' => '49', 'currency_code' => 'USD']]],
            ],
        ]);
        $ids = array_map(fn ($d) => $d->id, $this->makeGateway($fake)->getRemoteInvoices('I-SUB')['data']);
        $this->assertSame(['TX-OLD', 'TX-MID', 'TX-NEW'], $ids);
    }

    public function test_get_remote_invoices_cursor_advance_strict_after(): void
    {
        $fake = new FakePayPalApi([
            'transactions' => [
                ['id' => 'TX-1', 'status' => 'COMPLETED', 'time' => '2026-04-01T00:00:00Z',
                 'amount_with_breakdown' => ['gross_amount' => ['value' => '10', 'currency_code' => 'USD']]],
                ['id' => 'TX-2', 'status' => 'COMPLETED', 'time' => '2026-04-02T00:00:00Z',
                 'amount_with_breakdown' => ['gross_amount' => ['value' => '10', 'currency_code' => 'USD']]],
                ['id' => 'TX-3', 'status' => 'COMPLETED', 'time' => '2026-04-03T00:00:00Z',
                 'amount_with_breakdown' => ['gross_amount' => ['value' => '10', 'currency_code' => 'USD']]],
            ],
        ]);
        $ids = array_map(fn ($d) => $d->id, $this->makeGateway($fake)->getRemoteInvoices('I-SUB', 'TX-1')['data']);
        $this->assertSame(['TX-2', 'TX-3'], $ids);
    }

    public function test_get_remote_invoices_origin_is_recurring(): void
    {
        $fake = new FakePayPalApi([
            'transactions' => [[
                'id' => 'TX', 'status' => 'COMPLETED', 'time' => '2026-04-01T00:00:00Z',
                'amount_with_breakdown' => ['gross_amount' => ['value' => '10', 'currency_code' => 'USD']],
            ]],
        ]);
        $this->assertSame(BillingOrigin::RECURRING,
            $this->makeGateway($fake)->getRemoteInvoices('I-SUB')['data'][0]->origin);
    }

    public function test_get_remote_invoices_passes_time_window_query(): void
    {
        $fake = new FakePayPalApi(['transactions' => []]);
        $this->makeGateway($fake)->getRemoteInvoices('I-SUB');
        $this->assertArrayHasKey('start_time', $fake->lastQuery);
        $this->assertArrayHasKey('end_time', $fake->lastQuery);
    }

    // ── 5. Cancel / Resume / Update / Extract / GetRemotePaymentMethod ──

    public function test_cancel_remote_subscription_posts_to_cancel_endpoint(): void
    {
        $fake = new FakePayPalApi([]);
        $this->makeGateway($fake)->cancelRemoteSubscription('I-CANCEL-ME');
        $this->assertSame('POST', $fake->lastMethod);
        $this->assertSame('/v1/billing/subscriptions/I-CANCEL-ME/cancel', $fake->lastPath);
        $this->assertNotEmpty($fake->lastBody['reason']);
    }

    public function test_resume_swallows_already_active_error(): void
    {
        $fake = new FakePayPalApi();
        $fake->throwOnNextCall(new \RuntimeException(
            '[422] SUBSCRIPTION_STATUS_INVALID: Subscription is not in valid state for activate'
        ));
        $this->makeGateway($fake)->resumeRemoteSubscription('I-ALREADY-ACTIVE');
        $this->assertTrue(true);
    }

    public function test_resume_propagates_unrelated_errors(): void
    {
        $fake = new FakePayPalApi();
        $fake->throwOnNextCall(new \RuntimeException('[500] INTERNAL_SERVER_ERROR'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/INTERNAL_SERVER_ERROR/');
        $this->makeGateway($fake)->resumeRemoteSubscription('I-X');
    }

    public function test_update_subscription_plan_posts_revise_then_refetches(): void
    {
        $fake = new FakePayPalApi();
        $fake->queueResponses(
            [],
            [
                'id' => 'I-1', 'status' => 'ACTIVE', 'plan_id' => 'P-NEW',
                'subscriber' => ['payer_id' => 'PID', 'email_address' => 'a@b.c'],
            ]
        );
        $dto = $this->makeGateway($fake)->updateRemoteSubscriptionPlan('I-1', 'P-NEW');

        $this->assertSame('/v1/billing/subscriptions/I-1/revise', $fake->callHistory[0]['path']);
        $this->assertSame('POST', $fake->callHistory[0]['method']);
        $this->assertSame('P-NEW', $fake->callHistory[0]['body']['plan_id']);
        $this->assertSame('/v1/billing/subscriptions/I-1', $fake->callHistory[1]['path']);
        $this->assertSame('P-NEW', $dto->remotePlanId);
    }

    public function test_extract_remote_payment_method_id_returns_value_when_present(): void
    {
        $gw = $this->makeGateway(new FakePayPalApi());
        $this->assertSame('pp-tok-123',
            $gw->extractRemotePaymentMethodId(['paypal_payment_token_id' => 'pp-tok-123']));
    }

    public function test_extract_remote_payment_method_id_returns_null_when_missing(): void
    {
        $gw = $this->makeGateway(new FakePayPalApi());
        $this->assertNull($gw->extractRemotePaymentMethodId([]));
        $this->assertNull($gw->extractRemotePaymentMethodId(['unrelated' => 'x']));
    }

    public function test_get_remote_payment_method_returns_email_only(): void
    {
        $fake = new FakePayPalApi([
            'id' => 'I-1', 'status' => 'ACTIVE', 'plan_id' => 'P',
            'subscriber' => ['payer_id' => 'PID', 'email_address' => 'pp@x.c'],
        ]);
        $pm = $this->makeGateway($fake)->getRemotePaymentMethod('I-1');

        $this->assertInstanceOf(RemotePaymentMethodDTO::class, $pm);
        $this->assertSame('pp@x.c', $pm->email);
        $this->assertSame('paypal', $pm->type);
        $this->assertNull($pm->last4);
        $this->assertNull($pm->cardType);
    }

    public function test_get_remote_payment_method_returns_null_when_no_subscriber(): void
    {
        $fake = new FakePayPalApi(['id' => 'I-1', 'status' => 'ACTIVE']);
        $this->assertNull($this->makeGateway($fake)->getRemotePaymentMethod('I-1'));
    }

    // ── 6. Pagination + empty-page fallback ──────────────────────────────

    public function test_get_remote_plans_walks_pagination(): void
    {
        $fake = new FakePayPalApi();
        $fake->queueResponses(
            [   // page 1 summaries
                'plans' => [['id' => 'P-A'], ['id' => 'P-B']],
                'links' => [['rel' => 'next', 'href' => '/v1/billing/plans?page=2']],
            ],
            ['id' => 'P-A', 'name' => 'A', 'status' => 'ACTIVE',
             'billing_cycles' => [self::makeRegularCycle('10', 'USD')]],
            ['id' => 'P-B', 'name' => 'B', 'status' => 'ACTIVE',
             'billing_cycles' => [self::makeRegularCycle('20', 'USD')]],
            [   // page 2 summaries
                'plans' => [['id' => 'P-C']],
                'links' => [],
            ],
            ['id' => 'P-C', 'name' => 'C', 'status' => 'ACTIVE',
             'billing_cycles' => [self::makeRegularCycle('30', 'USD')]],
        );
        $plans = $this->makeGateway($fake)->getRemotePlans();
        $this->assertCount(3, $plans);
        $this->assertSame(['A', 'B', 'C'], array_map(fn ($p) => $p->name, $plans));
    }

    public function test_get_remote_subscriptions_empty_fallback_on_api_error(): void
    {
        $fake = new FakePayPalApi();
        $fake->throwOnNextCall(new \RuntimeException('[404] RESOURCE_NOT_FOUND'));
        $page = $this->makeGateway($fake)->getRemoteSubscriptions();
        $this->assertSame([], $page['data']);
        $this->assertFalse($page['has_more']);
        $this->assertNull($page['next_cursor']);
    }

    public function test_get_remote_subscriptions_advances_cursor_on_has_more(): void
    {
        $fake = new FakePayPalApi([
            'subscriptions' => [
                ['id' => 'I-1', 'status' => 'ACTIVE', 'plan_id' => 'P'],
                ['id' => 'I-2', 'status' => 'ACTIVE', 'plan_id' => 'P'],
            ],
            'links' => [['rel' => 'next', 'href' => '/v1/billing/subscriptions?page=2']],
        ]);
        $page = $this->makeGateway($fake)->getRemoteSubscriptions('1', 50);
        $this->assertCount(2, $page['data']);
        $this->assertTrue($page['has_more']);
        $this->assertSame('2', $page['next_cursor']);
    }

    // ── 7. Pull-only contract ─────────────────────────────────────────────

    public function test_get_webhook_secret_returns_null(): void
    {
        $this->assertNull($this->makeGateway(new FakePayPalApi())->getWebhookSecret());
    }

    public function test_parse_webhook_payload_returns_empty(): void
    {
        $this->assertSame([],
            $this->makeGateway(new FakePayPalApi())->parseWebhookPayload('any', []));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private static function makeRegularCycle(string $price, string $cur): array
    {
        return [
            'tenure_type'    => 'REGULAR',
            'frequency'      => ['interval_unit' => 'MONTH', 'interval_count' => 1],
            'pricing_scheme' => ['fixed_price' => ['value' => $price, 'currency_code' => $cur]],
        ];
    }
}

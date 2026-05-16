<?php

namespace Acelle\Paypal\Tests\Unit;

use Acelle\Paypal\Services\PayPalGateway;
use Acelle\Paypal\Support\PayPalApi;
use App\Cashier\DTO\BillingOrigin;
use App\Cashier\DTO\RemoteInvoiceDTO;
use App\Cashier\DTO\RemotePaymentMethodDTO;
use App\Cashier\DTO\RemotePlanDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use Tests\TestCase;

/**
 * Comprehensive unit tests for PayPalGateway. Ships to production, so cover
 * every contract surface:
 *   1. Currency guard + amount formatting (silent miscoding = wrong charge)
 *   2. Subscription create — custom_id round-trip identifier for sync
 *   3. Status / DTO mapping (PayPal JSON shape → local DTO shape)
 *   4. Pagination (HATEOAS next link walk)
 *   5. Error-path branches (empty-page fallback, idempotent resume)
 *   6. Endpoint paths (URL contract — bug here = wrong vendor side-effect)
 *   7. Interface-method semantics (createSubscription throws → forces hosted-checkout)
 *
 * Uses an injected fake PayPalApi (via reflection) — no live HTTP. Fake
 * supports both single canned response and per-call queue for pagination tests.
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

    // ═══════════════════════════════════════════════════════════════════════
    //  1. Currency guard + amount formatting
    // ═══════════════════════════════════════════════════════════════════════

    public function test_throws_on_unsupported_currency(): void
    {
        $gw = $this->makeGateway(new FakePayPalApi());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported currency .XYZ./');

        $gw->createOrder('intent-uid', 49.0, 'XYZ', 'desc', 'http://r', 'http://c');
    }

    public function test_supported_currencies_are_normalized_to_uppercase(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $gw   = $this->makeGateway($fake);

        $gw->createOrder('intent-uid', 10.0, 'usd', 'd', 'http://r', 'http://c');

        $this->assertSame('USD', $fake->lastBody['purchase_units'][0]['amount']['currency_code']);
    }

    public function test_formats_jpy_as_integer_string(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $gw   = $this->makeGateway($fake);

        $gw->createOrder('intent-uid', 49.5, 'JPY', 'd', 'http://r', 'http://c');

        $this->assertSame('50', $fake->lastBody['purchase_units'][0]['amount']['value']);
        $this->assertSame('JPY', $fake->lastBody['purchase_units'][0]['amount']['currency_code']);
    }

    public function test_formats_usd_as_two_decimal_string(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $gw   = $this->makeGateway($fake);

        $gw->createOrder('intent-uid', 49.0, 'USD', 'd', 'http://r', 'http://c');

        $this->assertSame('49.00', $fake->lastBody['purchase_units'][0]['amount']['value']);
    }

    public function test_formats_usd_fractional_to_two_decimal_with_no_trailing_zeros(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $gw   = $this->makeGateway($fake);

        $gw->createOrder('intent-uid', 49.555, 'EUR', 'd', 'http://r', 'http://c');

        // number_format(49.555, 2) = '49.56' (banker's round → up)
        $this->assertSame('49.56', $fake->lastBody['purchase_units'][0]['amount']['value']);
    }

    public function test_description_truncated_to_127_chars(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $gw   = $this->makeGateway($fake);

        $long = str_repeat('x', 200);
        $gw->createOrder('intent-uid', 1.0, 'USD', $long, 'http://r', 'http://c');

        $this->assertSame(127, strlen($fake->lastBody['purchase_units'][0]['description']));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  2. Subscription create — custom_id round-trip
    // ═══════════════════════════════════════════════════════════════════════

    public function test_subscription_create_uses_intent_uid_as_custom_id(): void
    {
        $fake = new FakePayPalApi(['id' => 'I-X', 'status' => 'APPROVAL_PENDING', 'links' => []]);
        $gw   = $this->makeGateway($fake);

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
        $this->assertSame('a@b.c', $fake->lastBody['subscriber']['email_address']);
        $this->assertSame('SUBSCRIBE_NOW', $fake->lastBody['application_context']['user_action']);
    }

    public function test_subscription_create_throws_on_missing_remote_plan_id(): void
    {
        $gw = $this->makeGateway(new FakePayPalApi());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/remote_plan_id/');

        $gw->createSubscriptionViaCheckout('uid', '', 'a@b.c', 'http://r', 'http://c');
    }

    public function test_create_subscription_interface_method_throws(): void
    {
        $gw = $this->makeGateway(new FakePayPalApi());

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

    // ═══════════════════════════════════════════════════════════════════════
    //  3. Order create — reference_id + endpoint
    // ═══════════════════════════════════════════════════════════════════════

    public function test_create_order_sends_reference_id_and_uses_orders_v2_endpoint(): void
    {
        $fake = new FakePayPalApi(['id' => 'o-1', 'links' => []]);
        $gw   = $this->makeGateway($fake);

        $gw->createOrder('local-intent-xyz', 10.0, 'USD', 'd', 'http://r', 'http://c');

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
        $gw   = $this->makeGateway($fake);

        $gw->captureOrder('PAYPAL-ORDER-1');

        $this->assertSame('POST', $fake->lastMethod);
        $this->assertSame('/v2/checkout/orders/PAYPAL-ORDER-1/capture', $fake->lastPath);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  4. subscriptionToDto — status normalization + field mapping
    // ═══════════════════════════════════════════════════════════════════════

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
            $gw  = $this->makeGateway($fake);
            $dto = $gw->getRemoteSubscription('I-1');

            $this->assertInstanceOf(RemoteSubscriptionDTO::class, $dto);
            $this->assertSame(
                $expectedLocal, $dto->status,
                "PayPal {$paypalStatus} should map to local {$expectedLocal}"
            );
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
        $this->assertNull($this->makeGateway($fakeActive)->getRemoteSubscription('I-3')->canceledAt,
            'Active sub should NOT have canceledAt set');

        $fakeCancelled = new FakePayPalApi([
            'id' => 'I-4', 'status' => 'CANCELLED', 'plan_id' => 'P',
            'status_update_time' => '2026-04-01T00:00:00Z',
        ]);
        $this->assertSame('2026-04-01',
            $this->makeGateway($fakeCancelled)->getRemoteSubscription('I-4')->canceledAt->toDateString());
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  5. planToDto — REGULAR/TRIAL pick, interval normalization, trial days
    // ═══════════════════════════════════════════════════════════════════════

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
        $this->assertSame(99.0, $dto->price, 'Price comes from REGULAR cycle, not TRIAL');
        $this->assertSame('USD', $dto->currency);
        $this->assertSame(1, $dto->intervalCount);
        $this->assertSame('month', $dto->intervalUnit);
        $this->assertSame('active', $dto->status);
        $this->assertSame(7, $dto->trialDays, '7-day TRIAL should be picked up as trialDays');
    }

    public function test_plan_to_dto_normalizes_interval_units(): void
    {
        $cases = [
            'DAY'   => 'day',
            'WEEK'  => 'week',
            'MONTH' => 'month',
            'YEAR'  => 'year',
            'UNKNOWN' => 'month',  // default fallback
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
            $this->assertSame($expectedLocal, $dto->intervalUnit,
                "PayPal {$paypalUnit} → local {$expectedLocal}");
            $this->assertSame(3, $dto->intervalCount);
        }
    }

    public function test_plan_to_dto_calculates_trial_days_from_unit(): void
    {
        $cases = [
            ['DAY',   2, 2],
            ['WEEK',  2, 14],
            ['MONTH', 1, 30],
            ['YEAR',  1, 365],
        ];
        foreach ($cases as [$unit, $count, $expectedDays]) {
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
            $this->assertSame($expectedDays, $dto->trialDays,
                "TRIAL {$count}x{$unit} → {$expectedDays} days");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  6. transactionToInvoiceDto — status mapping (paid/failed/refunded/open)
    // ═══════════════════════════════════════════════════════════════════════

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
                    'id'     => 'TX-' . $paypalStatus,
                    'status' => $paypalStatus,
                    'time'   => '2026-05-01T00:00:00Z',
                    'amount_with_breakdown' => [
                        'gross_amount' => ['value' => '49.00', 'currency_code' => 'USD'],
                    ],
                ]],
            ]);
            $page = $this->makeGateway($fake)->getRemoteInvoices('I-SUB');
            $this->assertCount(1, $page['data']);
            $dto = $page['data'][0];
            $this->assertInstanceOf(RemoteInvoiceDTO::class, $dto);
            $this->assertSame($expectedLocal, $dto->status,
                "Transaction {$paypalStatus} → local {$expectedLocal}");
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
        $page = $this->makeGateway($fake)->getRemoteInvoices('I-SUB');

        $ids = array_map(fn ($dto) => $dto->id, $page['data']);
        $this->assertSame(['TX-OLD', 'TX-MID', 'TX-NEW'], $ids,
            'getRemoteInvoices must sort oldest-first per interface contract');
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
        $page = $this->makeGateway($fake)->getRemoteInvoices('I-SUB', 'TX-1');

        $ids = array_map(fn ($dto) => $dto->id, $page['data']);
        $this->assertSame(['TX-2', 'TX-3'], $ids,
            'Cursor TX-1 means strict-after — should NOT include TX-1');
    }

    public function test_get_remote_invoices_origin_is_recurring(): void
    {
        $fake = new FakePayPalApi([
            'transactions' => [[
                'id' => 'TX', 'status' => 'COMPLETED', 'time' => '2026-04-01T00:00:00Z',
                'amount_with_breakdown' => ['gross_amount' => ['value' => '10', 'currency_code' => 'USD']],
            ]],
        ]);
        $dto = $this->makeGateway($fake)->getRemoteInvoices('I-SUB')['data'][0];
        $this->assertSame(BillingOrigin::RECURRING, $dto->origin);
    }

    public function test_get_remote_invoices_passes_time_window_query(): void
    {
        $fake = new FakePayPalApi(['transactions' => []]);
        $this->makeGateway($fake)->getRemoteInvoices('I-SUB');

        $this->assertArrayHasKey('start_time', $fake->lastQuery,
            'PayPal requires start_time on transactions query');
        $this->assertArrayHasKey('end_time', $fake->lastQuery,
            'PayPal requires end_time on transactions query');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  7. Cancel / Resume / Update / Extract
    // ═══════════════════════════════════════════════════════════════════════

    public function test_cancel_remote_subscription_posts_to_cancel_endpoint(): void
    {
        $fake = new FakePayPalApi([]);
        $gw   = $this->makeGateway($fake);

        $gw->cancelRemoteSubscription('I-CANCEL-ME');

        $this->assertSame('POST', $fake->lastMethod);
        $this->assertSame('/v1/billing/subscriptions/I-CANCEL-ME/cancel', $fake->lastPath);
        $this->assertArrayHasKey('reason', $fake->lastBody,
            'PayPal cancel requires non-empty reason in body');
        $this->assertNotEmpty($fake->lastBody['reason']);
    }

    public function test_resume_swallows_already_active_error(): void
    {
        // PayPal returns 422 with "SUBSCRIPTION_STATUS_INVALID" when sub is already
        // ACTIVE. We silently pass (idempotent) — admin double-click shouldn't error.
        $fake = new FakePayPalApi();
        $fake->throwOnNextCall(new \RuntimeException(
            '[422] SUBSCRIPTION_STATUS_INVALID: Subscription is not in valid state for activate'
        ));
        $gw = $this->makeGateway($fake);

        $gw->resumeRemoteSubscription('I-ALREADY-ACTIVE');

        $this->assertTrue(true, 'no exception means silent pass — correct idempotent behavior');
    }

    public function test_resume_propagates_unrelated_errors(): void
    {
        $fake = new FakePayPalApi();
        $fake->throwOnNextCall(new \RuntimeException('[500] INTERNAL_SERVER_ERROR'));
        $gw = $this->makeGateway($fake);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/INTERNAL_SERVER_ERROR/');

        $gw->resumeRemoteSubscription('I-X');
    }

    public function test_update_subscription_plan_posts_revise_then_refetches(): void
    {
        // Two-call dance: POST /revise (204-style empty), then GET sub to
        // refresh DTO. Queue 2 responses on the fake.
        $fake = new FakePayPalApi();
        $fake->queueResponses(
            [],  // POST /revise — empty body OK
            [    // GET /subscriptions/{id} — returns DTO-shape
                'id' => 'I-1', 'status' => 'ACTIVE', 'plan_id' => 'P-NEW',
                'subscriber' => ['payer_id' => 'PID', 'email_address' => 'a@b.c'],
            ]
        );
        $gw  = $this->makeGateway($fake);
        $dto = $gw->updateRemoteSubscriptionPlan('I-1', 'P-NEW');

        $this->assertSame('/v1/billing/subscriptions/I-1/revise', $fake->callHistory[0]['path']);
        $this->assertSame('POST', $fake->callHistory[0]['method']);
        $this->assertSame('P-NEW', $fake->callHistory[0]['body']['plan_id']);

        $this->assertSame('/v1/billing/subscriptions/I-1', $fake->callHistory[1]['path']);
        $this->assertSame('P-NEW', $dto->remotePlanId, 'returned DTO must reflect new plan');
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
        $this->assertNull($pm->last4, 'PayPal anonymizes card last4');
        $this->assertNull($pm->cardType);
    }

    public function test_get_remote_payment_method_returns_null_when_no_subscriber(): void
    {
        $fake = new FakePayPalApi(['id' => 'I-1', 'status' => 'ACTIVE']);
        $this->assertNull($this->makeGateway($fake)->getRemotePaymentMethod('I-1'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  8. Pagination + empty-page fallback
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_remote_plans_walks_pagination(): void
    {
        // page 1: 2 plan summaries + next link
        // page 1 each-plan-detail × 2
        // page 2: 1 plan + no next
        // page 2 each-plan-detail × 1
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
        // Some PayPal tenancies disable the list endpoint; sync layer should
        // gracefully fall through to empty page rather than crash.
        $fake = new FakePayPalApi();
        $fake->throwOnNextCall(new \RuntimeException('[404] RESOURCE_NOT_FOUND'));
        $gw = $this->makeGateway($fake);

        $page = $gw->getRemoteSubscriptions();

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
        $this->assertSame('2', $page['next_cursor'],
            'Cursor advances by +1 page when has_more');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  9. Pull-only contract: webhook stubs return inert values
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_webhook_secret_returns_null(): void
    {
        $this->assertNull($this->makeGateway(new FakePayPalApi())->getWebhookSecret());
    }

    public function test_parse_webhook_payload_returns_empty(): void
    {
        $this->assertSame([],
            $this->makeGateway(new FakePayPalApi())->parseWebhookPayload('any', []));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  10. IntentGatewayInterface basics
    // ═══════════════════════════════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════════════════════════════

    private static function makeRegularCycle(string $price, string $cur): array
    {
        return [
            'tenure_type'    => 'REGULAR',
            'frequency'      => ['interval_unit' => 'MONTH', 'interval_count' => 1],
            'pricing_scheme' => ['fixed_price' => ['value' => $price, 'currency_code' => $cur]],
        ];
    }
}

/**
 * Test double for PayPalApi.
 *
 * Three modes:
 *   1. Static canned response — single response returned every call (legacy ctor arg)
 *   2. Queue mode — `queueResponses(...)` to return different responses per call
 *   3. Throw mode — `throwOnNextCall(...)` queues a single \Throwable for the next call
 *
 * Captures every call into `$callHistory` so tests can introspect call order.
 * `lastMethod / lastPath / lastBody / lastQuery` mirror the latest call (back-
 * compat for simple cases).
 */
class FakePayPalApi extends PayPalApi
{
    public ?string $lastMethod = null;
    public ?string $lastPath   = null;
    public array $lastBody     = [];
    public array $lastQuery    = [];
    public array $callHistory  = [];
    private array $responseQueue = [];
    private ?\Throwable $nextThrow = null;
    private bool $useQueue = false;
    private array $staticResponse = [];

    public function __construct(array $response = [])
    {
        $this->staticResponse = $response;
    }

    public function queueResponses(array ...$responses): void
    {
        $this->responseQueue = $responses;
        $this->useQueue      = true;
    }

    public function throwOnNextCall(\Throwable $e): void
    {
        $this->nextThrow = $e;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->capture('GET', $path, [], $query);
    }

    public function post(string $path, array $body): array
    {
        return $this->capture('POST', $path, $body, []);
    }

    public function patch(string $path, array $body): array
    {
        return $this->capture('PATCH', $path, $body, []);
    }

    private function capture(string $method, string $path, array $body, array $query): array
    {
        $this->lastMethod = $method;
        $this->lastPath   = $path;
        $this->lastBody   = $body;
        $this->lastQuery  = $query;
        $this->callHistory[] = compact('method', 'path', 'body', 'query');

        if ($this->nextThrow !== null) {
            $t = $this->nextThrow;
            $this->nextThrow = null;
            throw $t;
        }
        if ($this->useQueue) {
            if (empty($this->responseQueue)) {
                throw new \LogicException('FakePayPalApi: queue exhausted at call to ' . $method . ' ' . $path);
            }
            return array_shift($this->responseQueue);
        }
        return $this->staticResponse;
    }
}

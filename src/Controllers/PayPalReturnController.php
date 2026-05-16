<?php

namespace Acelle\Paypal\Controllers;

use Acelle\Paypal\Services\PayPalGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;
use App\Http\Controllers\Controller;
use App\Library\Facades\Billing;
use App\Model\PaymentIntent;
use Illuminate\Http\Request;

/**
 * Browser return URL after PayPal hosted checkout / subscription approval.
 *
 * Three branches:
 *   1. ?cancel=1                  — customer clicked Cancel at PayPal page.
 *                                   Dispatch onPaymentFailed with the
 *                                   user-cancelled reason; banner shows up.
 *   2. ?subscription_id=I-...     — subscription approved. GetSubscription,
 *                                   if active dispatch onSubscriptionCreated.
 *   3. ?token={paypal_order_id}   — one-off approved. Capture, dispatch
 *                                   onPaymentSuccess (or onPaymentFailed if
 *                                   the capture itself fails — declined card,
 *                                   risk hold, etc.).
 *
 * Always redirects to the merchant return_url at the end — even on failure —
 * so the customer lands somewhere coherent. Failure detail surfaces via
 * intent.failed_reason + the payment-page "Last attempt failed" banner.
 *
 * Idempotent: if the customer double-clicks Back or hits the return URL twice
 * after a successful approve, CheckoutHandler's Invoice::isNew() guard
 * refuses to re-flip an already-paid invoice — second call is a no-op.
 */
class PayPalReturnController extends Controller
{
    public function handle(Request $request, string $intentUid)
    {
        $merchantReturn = (string) $request->query('return_url', url('/'));

        $intent = PaymentIntent::where('uid', $intentUid)->first();
        if (!$intent) {
            \Log::warning('PayPal return: intent not found', ['uid' => $intentUid]);
            return redirect()->away($merchantReturn);
        }

        // If the intent is already terminal (race with a previous return-URL
        // hit or sync pass), nothing to do — hand off to merchant.
        if ($intent->status !== \App\Model\PaymentIntent::STATUS_PENDING
            && $intent->status !== \App\Model\PaymentIntent::STATUS_REQUIRES_ACTION) {
            return redirect()->away($merchantReturn);
        }

        $intent->load(['paymentGateway', 'invoice.customer']);
        try {
            $service = Billing::resolveService($intent->paymentGateway);
            if (!$service instanceof PayPalGateway) {
                return redirect()->away($merchantReturn);
            }

            $handler   = app(CheckoutHandlerInterface::class);
            $intentDto = $intent->toDto();
            $payerEmail = (string) ($intent->invoice?->billing_email ?: $intent->invoice?->customer?->email ?: '');

            // Build a placeholder PaymentMethod row. PayPal V1 doesn't vault
            // card data locally (PayPal owns the funding source); we record
            // the PayPal order/subscription id for traceability + audit.
            $pm = $handler->createPaymentMethod($intentDto, [
                'paypal_order_id'        => (string) ($intent->metadata['paypal_order_id'] ?? ''),
                'paypal_subscription_id' => (string) ($intent->metadata['paypal_subscription_id'] ?? ''),
                'payer_email'            => $payerEmail,
                'card_type'              => 'PayPal',
                'last_4'                 => '',
            ]);

            // ─── Branch 1: cancel ────────────────────────────────────────
            if ($request->query('cancel')) {
                \Log::info('PayPal return: customer cancelled at PayPal', ['intent_uid' => $intentUid]);
                $handler->onPaymentFailed(
                    $intentDto,
                    $pm,
                    trans('paypal::messages.checkout.cancelled')
                );
                return redirect()->away($merchantReturn);
            }

            $paypalSubId   = (string) ($request->query('subscription_id') ?? '');
            $paypalOrderId = (string) ($request->query('token') ?? '');
            if (!$paypalSubId) {
                $paypalSubId = (string) ($intent->metadata['paypal_subscription_id'] ?? '');
            }
            if (!$paypalOrderId) {
                $paypalOrderId = (string) ($intent->metadata['paypal_order_id'] ?? '');
            }

            // ─── Branch 2: subscription approved ─────────────────────────
            if ($paypalSubId !== '') {
                $sub = $service->getRemoteSubscription($paypalSubId);
                \Log::info('PayPal return: subscription state polled', [
                    'intent_uid'    => $intentUid,
                    'paypal_sub_id' => $paypalSubId,
                    'status'        => $sub->status,
                ]);

                match ($sub->status) {
                    'active' => $handler->onSubscriptionCreated($intentDto, [
                        'remote_subscription_id'      => $paypalSubId,
                        'remote_customer_id'          => $sub->remoteCustomerId,
                        'current_period_end'          => $sub->currentPeriodEnd?->timestamp,
                        'payment_method_data'         => [
                            'payer_email'             => $payerEmail,
                            'paypal_subscription_id'  => $paypalSubId,
                        ],
                    ]),

                    // PayPal sometimes lands customers on return URL while
                    // subscription is still "incomplete" (APPROVAL_PENDING /
                    // APPROVED — PayPal hasn't fully activated the agreement).
                    // The sync layer will catch up. Don't dispatch yet —
                    // leave intent PENDING.
                    'incomplete' => null,

                    'canceled', 'paused' => $handler->onPaymentFailed(
                        $intentDto,
                        $pm,
                        "PayPal subscription state: {$sub->status}"
                    ),

                    // Unknown / unhandled — log and fail loud so we don't
                    // silently leave the customer in limbo.
                    default => $this->onUnknownStatus($handler, $intentDto, $pm, $intentUid, $sub->status),
                };

                return redirect()->away($merchantReturn);
            }

            // ─── Branch 3: one-off approved (capture flow) ───────────────
            if ($paypalOrderId === '') {
                \Log::warning('PayPal return: no order/subscription id available', [
                    'intent_uid' => $intentUid,
                    'query'      => $request->query(),
                ]);
                return redirect()->away($merchantReturn);
            }

            try {
                $captureResp = $service->captureOrder($paypalOrderId);
            } catch (\Throwable $e) {
                \Log::warning('PayPal return: capture failed', [
                    'intent_uid'       => $intentUid,
                    'paypal_order_id'  => $paypalOrderId,
                    'error'            => $e->getMessage(),
                ]);
                $handler->onPaymentFailed($intentDto, $pm, $e->getMessage());
                return redirect()->away($merchantReturn);
            }

            $captureStatus = strtoupper((string) ($captureResp['status'] ?? ''));
            \Log::info('PayPal return: capture polled', [
                'intent_uid'      => $intentUid,
                'paypal_order_id' => $paypalOrderId,
                'capture_status'  => $captureStatus,
            ]);

            match ($captureStatus) {
                'COMPLETED' => $handler->onPaymentSuccess($intentDto, $pm, $paypalOrderId),

                'PENDING', 'PAYER_ACTION_REQUIRED' => null,  // PayPal needs more time — sync will fix

                'DECLINED', 'FAILED', 'VOIDED' => $handler->onPaymentFailed(
                    $intentDto,
                    $pm,
                    "PayPal capture status: {$captureStatus}"
                ),

                default => $this->onUnknownStatus($handler, $intentDto, $pm, $intentUid, $captureStatus),
            };
        } catch (\Throwable $e) {
            // Don't fail the redirect — log + continue. Sync layer is the safety net.
            \Log::warning('PayPal return: dispatch failed (non-fatal)', [
                'intent_uid' => $intentUid,
                'error'      => $e->getMessage(),
            ]);
        }

        return redirect()->away($merchantReturn);
    }

    private function onUnknownStatus(
        CheckoutHandlerInterface $handler,
        \App\Cashier\DTO\PaymentIntent $intentDto,
        \App\Cashier\Contracts\PaymentMethodInfoInterface $pm,
        string $intentUid,
        string $status
    ): void {
        \Log::warning('PayPal return: unknown vendor status', [
            'intent_uid' => $intentUid,
            'status'     => $status,
        ]);
        $handler->onPaymentFailed($intentDto, $pm, "PayPal returned unknown status: {$status}");
    }
}

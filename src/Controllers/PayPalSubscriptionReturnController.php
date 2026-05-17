<?php

namespace Acelle\Paypal\Controllers;

use Acelle\Paypal\Services\PayPalSubscriptionGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;
use App\Http\Controllers\Controller;
use App\Library\Facades\Billing;
use App\Model\PaymentIntent;
use Illuminate\Http\Request;

/**
 * PayPal Subscription return URL — fires after customer approves (or cancels)
 * the recurring subscription at PayPal. Two branches:
 *   - ?cancel=1                     → onPaymentFailed("User cancelled at PayPal")
 *   - ?subscription_id=I-...  (or stamped on metadata)
 *                                   → getRemoteSubscription, if ACTIVE
 *                                     dispatch onSubscriptionCreated; if
 *                                     terminal-bad dispatch onPaymentFailed.
 *
 * Idempotent. Always redirects to merchant return_url at the end.
 */
class PayPalSubscriptionReturnController extends Controller
{
    public function handle(Request $request, string $intentUid)
    {
        $merchantReturn = (string) $request->query('return_url', url('/'));

        $intent = PaymentIntent::where('uid', $intentUid)->first();
        if (!$intent) {
            \Log::warning('PayPal sub return: intent not found', ['uid' => $intentUid]);
            return redirect()->away($merchantReturn);
        }

        if ($intent->status !== \App\Model\PaymentIntent::STATUS_PENDING
            && $intent->status !== \App\Model\PaymentIntent::STATUS_REQUIRES_ACTION) {
            return redirect()->away($merchantReturn);
        }

        $intent->load(['paymentGateway', 'invoice.customer']);
        try {
            $service = Billing::resolveService($intent->paymentGateway);
            if (!$service instanceof PayPalSubscriptionGateway) {
                return redirect()->away($merchantReturn);
            }

            $handler   = app(CheckoutHandlerInterface::class);
            $intentDto = $intent->toDto();
            $payerEmail = (string) ($intent->invoice?->billing_email ?: $intent->invoice?->customer?->email ?: '');

            $pm = $handler->createPaymentMethod($intentDto, [
                'paypal_subscription_id' => (string) ($intent->metadata['paypal_subscription_id'] ?? ''),
                'payer_email'            => $payerEmail,
                'card_type'              => 'PayPal',
                'last_4'                 => '',
            ]);

            // ─── cancel branch
            if ($request->query('cancel')) {
                \Log::info('PayPal sub return: customer cancelled', ['intent_uid' => $intentUid]);
                $handler->onPaymentFailed($intentDto, $pm, trans('paypal::messages.checkout.cancelled'));
                return redirect()->away($merchantReturn);
            }

            $paypalSubId = (string) ($request->query('subscription_id') ?? '');
            if (!$paypalSubId) {
                $paypalSubId = (string) ($intent->metadata['paypal_subscription_id'] ?? '');
            }
            if ($paypalSubId === '') {
                \Log::warning('PayPal sub return: no subscription id available', [
                    'intent_uid' => $intentUid,
                    'query'      => $request->query(),
                ]);
                return redirect()->away($merchantReturn);
            }

            $sub = $service->getRemoteSubscription($paypalSubId);
            \Log::info('PayPal sub return: state polled', [
                'intent_uid'    => $intentUid,
                'paypal_sub_id' => $paypalSubId,
                'status'        => $sub->status,
            ]);

            match ($sub->status) {
                'active' => $handler->onSubscriptionCreated($intentDto, [
                    'remote_subscription_id' => $paypalSubId,
                    'remote_customer_id'     => $sub->remoteCustomerId,
                    'current_period_end'     => $sub->currentPeriodEnd?->timestamp,
                    'payment_method_data'    => [
                        'payer_email'            => $payerEmail,
                        'paypal_subscription_id' => $paypalSubId,
                    ],
                ]),

                // Sub still APPROVAL_PENDING / APPROVED — leave intent PENDING,
                // sync layer will catch up.
                'incomplete' => null,

                'canceled', 'paused' => $handler->onPaymentFailed(
                    $intentDto, $pm,
                    "PayPal subscription state: {$sub->status}"
                ),

                default => $this->onUnknownStatus($handler, $intentDto, $pm, $intentUid, $sub->status),
            };
        } catch (\Throwable $e) {
            \Log::warning('PayPal sub return: dispatch failed (non-fatal)', [
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
        \Log::warning('PayPal sub return: unknown status', [
            'intent_uid' => $intentUid,
            'status'     => $status,
        ]);
        $handler->onPaymentFailed($intentDto, $pm, "PayPal returned unknown subscription status: {$status}");
    }
}

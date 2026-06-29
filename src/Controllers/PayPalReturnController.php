<?php

namespace Acelle\Paypal\Controllers;

use Acelle\Paypal\Services\PayPalGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;
use App\Http\Controllers\Controller;
use App\Library\Facades\Billing;
use App\Model\PaymentIntent;
use Illuminate\Http\Request;

/**
 * One-off PayPal return URL — fires after customer approves (or cancels) at
 * PayPal hosted checkout. Two branches:
 *   - ?cancel=1            → onPaymentFailed("User cancelled at PayPal")
 *   - ?token={paypal_order_id} (or stamped on metadata)
 *                          → captureOrder, dispatch onPaymentSuccess /
 *                            onPaymentFailed based on capture status.
 *
 * Idempotent — repeat hits skip via Invoice::isNew() guard inside
 * CheckoutHandler::onPaymentSuccess. Always redirects to merchant return_url
 * at the end so the customer lands somewhere coherent.
 */
class PayPalReturnController extends Controller
{
    public function handle(Request $request, string $intentUid)
    {
        $merchantReturn = (string) $request->query('return_url', url('/'));

        $intent = PaymentIntent::where('uid', $intentUid)->first();
        if (!$intent) {
            \Log::warning('PayPal one-off return: intent not found', ['uid' => $intentUid]);
            return redirect()->away($merchantReturn);
        }

        if ($intent->status !== \App\Model\PaymentIntent::STATUS_PENDING
            && $intent->status !== \App\Model\PaymentIntent::STATUS_REQUIRES_ACTION) {
            return redirect()->away($merchantReturn);
        }

        $intent->load(['paymentGateway', 'invoice.customer']);
        $service = Billing::resolveService($intent->paymentGateway);
        if (!$service instanceof PayPalGateway) {
            return redirect()->away($merchantReturn);
        }

        $handler   = app(CheckoutHandlerInterface::class);
        $intentDto = $intent->toDto();

        // ─── cancel branch
        if ($request->query('cancel')) {
            \Log::info('PayPal one-off return: customer cancelled', ['intent_uid' => $intentUid]);
            $handler->onPaymentFailed($intentDto, trans('paypal::messages.checkout.cancelled'));
            return redirect()->away($merchantReturn);
        }

        $paypalOrderId = (string) ($request->query('token')
            ?: ($intent->metadata['paypal_order_id'] ?? ''));
        if ($paypalOrderId === '') {
            \Log::warning('PayPal one-off return: no order id available', [
                'intent_uid' => $intentUid,
                'query'      => $request->query(),
            ]);
            return redirect()->away($merchantReturn);
        }

        // Capture is the one EXPECTED transient failure point — catch + fail the intent so the
        // customer sees the failure banner. A completion-dispatch bug below is NOT caught (crashes loud).
        try {
            $captureResp = $service->captureOrder($paypalOrderId);
        } catch (\Throwable $e) {
            \Log::warning('PayPal one-off return: capture failed', [
                'intent_uid'      => $intentUid,
                'paypal_order_id' => $paypalOrderId,
                'error'           => $e->getMessage(),
            ]);
            $handler->onPaymentFailed($intentDto, $e->getMessage());
            return redirect()->away($merchantReturn);
        }

        $captureStatus = strtoupper((string) ($captureResp['status'] ?? ''));
        \Log::info('PayPal one-off return: capture polled', [
            'intent_uid'      => $intentUid,
            'paypal_order_id' => $paypalOrderId,
            'capture_status'  => $captureStatus,
        ]);

        match ($captureStatus) {
            'COMPLETED' => $handler->onPaymentSuccess($intentDto, $paypalOrderId),

            // PayPal hasn't finalised yet — leave intent PENDING.
            'PENDING', 'PAYER_ACTION_REQUIRED' => null,

            'DECLINED', 'FAILED', 'VOIDED' => $handler->onPaymentFailed(
                $intentDto,
                "PayPal capture status: {$captureStatus}"
            ),

            default => $this->onUnknownStatus($handler, $intentDto, $intentUid, $captureStatus),
        };

        return redirect()->away($merchantReturn);
    }

    private function onUnknownStatus(
        CheckoutHandlerInterface $handler,
        \App\Cashier\DTO\PaymentIntent $intentDto,
        string $intentUid,
        string $status
    ): void {
        \Log::warning('PayPal one-off return: unknown capture status', [
            'intent_uid' => $intentUid,
            'status'     => $status,
        ]);
        $handler->onPaymentFailed($intentDto, "PayPal returned unknown status: {$status}");
    }
}

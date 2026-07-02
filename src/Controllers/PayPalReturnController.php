<?php

namespace Acelle\Paypal\Controllers;

use Acelle\Paypal\Services\PayPalGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;
use App\Cashier\DTO\PaymentIntent as PaymentIntentDto;
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
            'COMPLETED' => $this->onSuccess($handler, $intentDto, $captureResp, $paypalOrderId, $intentUid),

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

    /**
     * First-payment success: if PayPal vaulted the wallet during purchase, persist the
     * vault id as the customer's saved PaymentMethod (so autoCharge() can replay it on
     * renewal), THEN mark the invoice paid.
     *
     * The vault id lives at `payment_source.paypal.attributes.vault.id` on the capture
     * response, with `.status === 'VAULTED'`. We persist ONLY when a real vaulted id was
     * issued — autoCharge:true is set only in that case (never blindly). A pure one-off
     * buyer with no future subscription simply gets a card that's never auto-charged.
     */
    private function onSuccess(
        CheckoutHandlerInterface $handler,
        PaymentIntentDto $intentDto,
        array $captureResp,
        string $paypalOrderId,
        string $intentUid
    ): void {
        $savedCard = PayPalGateway::buildSavedPaymentMethod($captureResp);

        if ($savedCard !== null) {
            $handler->createPaymentMethod($intentDto, $savedCard);
        } else {
            // No vault id (buyer's account / merchant lacks Reference-Transactions
            // underwriting, or PayPal didn't vault) — first payment still collected,
            // but this method is NOT auto-chargeable. If a subscription depends on it,
            // the host's renewal will fail with "missing paypal_vault_id" until admin
            // resolves (usually: enable Reference Transactions / ACDC+Vault on the app).
            \Log::warning('PayPal one-off return: payment COMPLETED but no vault id issued — auto-renewal will fail', [
                'intent_uid'      => $intentUid,
                'paypal_order_id' => $paypalOrderId,
            ]);
        }

        $handler->onPaymentSuccess($intentDto, $paypalOrderId);
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

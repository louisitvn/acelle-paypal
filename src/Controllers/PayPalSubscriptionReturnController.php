<?php

namespace Acelle\Paypal\Controllers;

use Acelle\Paypal\Services\PayPalSubscriptionGateway;
use App\Cashier\Contracts\CheckoutHandlerInterface;
use App\Http\Controllers\Controller;
use App\Library\Facades\Billing;
use App\Model\PaymentIntent;
use Illuminate\Http\Request;

/**
 * PayPal Subscription return URL — fires after the buyer approves (or cancels) the recurring
 * subscription at PayPal, then completes it on the browser-return (the primary completion
 * trigger; the pull-sync layer is the backup). Mirrors the 2C2P return-controller shape:
 *
 *   - ?cancel=1                  → onPaymentFailed("cancelled at PayPal").
 *   - otherwise                  → poll getCheckoutSession(subId) (absorbing PayPal's
 *                                  activation lag) and, once ACTIVE, fire onSubscriptionCreated
 *                                  with the canonical RemoteSubscriptionDTO + the PayPal payer
 *                                  as the saved method. Not-yet-active → defer to the sync layer.
 *
 * The session id (= the PayPal subscription id) is the host's `remote_reference_id`, stamped by
 * getCheckoutUrl. Idempotent (intent-status guard). Always redirects to return_url.
 */
class PayPalSubscriptionReturnController extends Controller
{
    /** Browser-return inquiry poll — absorbs PayPal's post-approval activation lag (≤ ~8s). */
    private const RECONCILE_ATTEMPTS = 5;
    private const RECONCILE_DELAY_US = 2_000_000; // 2s between attempts

    public function handle(Request $request, string $intentUid)
    {
        $merchantReturn = (string) $request->query('return_url', url('/'));

        $intent = PaymentIntent::where('uid', $intentUid)->first();
        if (!$intent) {
            \Log::warning('PayPal sub return: intent not found', ['uid' => $intentUid]);
            return redirect()->away($merchantReturn);
        }

        $intent->load(['paymentGateway']);
        $service = Billing::resolveService($intent->paymentGateway);
        if (!$service instanceof PayPalSubscriptionGateway) {
            return redirect()->away($merchantReturn);
        }

        // Already settled (a prior return or the sync layer beat us) — bounce.
        if ($intent->status !== PaymentIntent::STATUS_PENDING
            && $intent->status !== PaymentIntent::STATUS_REQUIRES_ACTION) {
            return redirect()->away($merchantReturn);
        }

        $handler   = app(CheckoutHandlerInterface::class);
        $intentDto = $intent->toDto();

        // Buyer cancelled at PayPal.
        if ($request->query('cancel')) {
            \Log::info('PayPal sub return: customer cancelled', ['intent_uid' => $intentUid]);
            $handler->onPaymentFailed($intentDto, trans('paypal::messages.checkout.cancelled'));
            return redirect()->away($merchantReturn);
        }

        // Session handle = the PayPal subscription id (host stamps it on remote_reference_id;
        // accept PayPal's return query / legacy metadata as fallbacks).
        $subId = (string) ($intent->remote_reference_id
            ?: $request->query('subscription_id')
            ?: ($intent->metadata['paypal_subscription_id'] ?? ''));
        if ($subId === '') {
            \Log::warning('PayPal sub return: no subscription id available', [
                'intent_uid' => $intentUid,
                'query'      => $request->query(),
            ]);
            return redirect()->away($merchantReturn);
        }

        // Poll the inquiry (the one EXPECTED transient failure point) until the subscription is
        // ACTIVE, then defer. Caught + retried; a genuine completion failure (onSubscriptionCreated
        // below) is OUTSIDE this guard and crashes loud — never swallowed.
        $session = null;
        for ($attempt = 1; $attempt <= self::RECONCILE_ATTEMPTS; $attempt++) {
            try {
                $candidate = $service->getCheckoutSession($subId);
                if ($candidate->isComplete()) {
                    $session = $candidate;
                    break;
                }
                if ($candidate->isExpired()) {
                    $handler->onPaymentFailed($intentDto, 'PayPal subscription was cancelled/expired before activation');
                    return redirect()->away($merchantReturn);
                }
            } catch (\Throwable $e) {
                \Log::warning('PayPal sub return: inquiry failed — will retry/defer', [
                    'intent_uid' => $intentUid,
                    'error'      => $e->getMessage(),
                ]);
            }
            if ($attempt < self::RECONCILE_ATTEMPTS) {
                usleep(self::RECONCILE_DELAY_US);
            }
        }

        if ($session === null) {
            \Log::info('PayPal sub return: not active yet — deferring to the sync layer', [
                'intent_uid' => $intentUid,
                'paypal_sub_id' => $subId,
            ]);
            return redirect()->away($merchantReturn);
        }

        // COMPLETE — commit via the shared handler. Card-read is non-fatal (narrow catch);
        // onSubscriptionCreated is NOT caught.
        $remoteSub = $service->getRemoteSubscription($session->remoteSubscriptionId);
        $pm = null;
        try {
            $pm = $service->getRemotePaymentMethod($session->remoteSubscriptionId);
        } catch (\Throwable $e) {
            \Log::warning('PayPal sub return: getRemotePaymentMethod failed (non-fatal)', [
                'intent_uid' => $intentUid,
                'error'      => $e->getMessage(),
            ]);
        }

        $handler->onSubscriptionCreated($intentDto, $remoteSub, $pm);

        \Log::info('PayPal sub return: subscription activated on browser-return', [
            'intent_uid'    => $intentUid,
            'paypal_sub_id' => $session->remoteSubscriptionId,
        ]);

        return redirect()->away($merchantReturn);
    }
}

<?php

namespace Acelle\Paypal\Controllers;

use Acelle\Paypal\Services\PayPalGateway;
use Acelle\Paypal\Support\PayPalApi;
use App\Http\Controllers\Controller;
use App\Library\Facades\Billing;
use App\Model\PaymentIntent;
use Illuminate\Http\Request;

/**
 * One-off PayPal checkout entry — receives customer redirect after they pick
 * the `paypal` gateway. Calls Orders API v2 to create the Order, stamps the
 * `paypal_order_id` on intent.metadata, redirects to PayPal's approve link.
 *
 * On Init failure: catches Throwable, logs, bounces back to merchant return URL
 * with `alert-error` flash so the payment-page banner surfaces the reason.
 */
class PayPalCheckoutController extends Controller
{
    public function redirect(Request $request, string $intentUid)
    {
        $intent = PaymentIntent::where('uid', $intentUid)->first();
        if (!$intent) {
            abort(404, 'Payment intent not found');
        }

        $intent->load(['invoice.customer', 'paymentGateway']);
        $service = Billing::resolveService($intent->paymentGateway);
        if (!$service instanceof PayPalGateway) {
            abort(400, 'Intent is not bound to a PayPal (one-off) gateway');
        }

        $invoice  = $intent->invoice;
        $customer = $invoice?->customer;
        if (!$customer) {
            abort(400, 'Invoice has no customer');
        }

        $merchantReturn = (string) $request->query('return_url', url('/'));
        $browserReturn = route('paypal.return', ['intent_uid' => $intent->uid])
            . '?return_url=' . urlencode($merchantReturn);
        $browserCancel = $browserReturn . '&cancel=1';

        try {
            $resp = $service->createOrder(
                intentUid:   $intent->uid,
                amountMajor: (float) $intent->amount,
                currency:    (string) ($intent->currency ?: 'USD'),
                description: (string) ($intent->description ?: 'Invoice'),
                returnUrl:   $browserReturn,
                cancelUrl:   $browserCancel,
            );
        } catch (\Throwable $e) {
            \Log::error('PayPal one-off: create failed', [
                'intent_uid' => $intent->uid,
                'error'      => $e->getMessage(),
            ]);
            return redirect()->away($merchantReturn)
                ->with('alert-error', trans('paypal::messages.checkout.create_failed', ['error' => $e->getMessage()]));
        }

        $orderId    = (string) ($resp['id'] ?? '');
        $approveUrl = PayPalApi::linkRel($resp['links'] ?? [], 'approve')
                   ?: PayPalApi::linkRel($resp['links'] ?? [], 'payer-action');

        if (!$orderId || !$approveUrl) {
            \Log::error('PayPal one-off: Init response missing id/approve link', [
                'intent_uid' => $intent->uid,
                'response'   => $resp,
            ]);
            abort(502, 'PayPal did not return a checkout URL');
        }

        $meta = is_array($intent->metadata) ? $intent->metadata : [];
        $meta['paypal_order_id'] = $orderId;
        $intent->metadata = $meta;
        $intent->save();

        return redirect()->away($approveUrl);
    }
}

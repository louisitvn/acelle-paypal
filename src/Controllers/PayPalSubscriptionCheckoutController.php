<?php

namespace Acelle\Paypal\Controllers;

use Acelle\Paypal\Services\PayPalSubscriptionGateway;
use Acelle\Paypal\Support\PayPalApi;
use App\Http\Controllers\Controller;
use App\Library\Facades\Billing;
use App\Model\PaymentIntent;
use Illuminate\Http\Request;

/**
 * PayPal Subscription checkout entry — receives customer redirect after they
 * pick the `paypal-subscription` gateway. Resolves the local plan → PayPal
 * Billing Plan ID via PlanRemoteMapping, calls Subscriptions v1 to create the
 * subscription, stamps `paypal_subscription_id` on intent.metadata, redirects
 * to PayPal's approve link.
 *
 * On Init failure: catches Throwable, logs, bounces back to merchant return
 * URL with `alert-error` flash.
 */
class PayPalSubscriptionCheckoutController extends Controller
{
    public function redirect(Request $request, string $intentUid)
    {
        $intent = PaymentIntent::where('uid', $intentUid)->first();
        if (!$intent) {
            abort(404, 'Payment intent not found');
        }

        $intent->load(['invoice.customer', 'paymentGateway', 'invoice.order.orderItems.subscription.plan']);
        $service = Billing::resolveService($intent->paymentGateway);
        if (!$service instanceof PayPalSubscriptionGateway) {
            abort(400, 'Intent is not bound to a PayPal Subscription gateway');
        }

        $invoice  = $intent->invoice;
        $customer = $invoice?->customer;
        if (!$customer) {
            abort(400, 'Invoice has no customer');
        }

        $merchantReturn = (string) $request->query('return_url', url('/'));
        $browserReturn = route('paypal-subscription.return', ['intent_uid' => $intent->uid])
            . '?return_url=' . urlencode($merchantReturn);
        $browserCancel = $browserReturn . '&cancel=1';

        $customerEmail = (string) ($invoice->billing_email ?: $customer->email);
        $remotePlanId  = $this->resolveRemotePlanId($intent);

        if (!$remotePlanId) {
            \Log::warning('PayPal sub checkout: no remote_plan_id resolvable', [
                'intent_uid' => $intent->uid,
            ]);
            return redirect()->away($merchantReturn)
                ->with('alert-error', trans('paypal::messages.subscription.no_plan_mapped'));
        }

        try {
            $resp = $service->createSubscriptionViaCheckout(
                intentUid:     $intent->uid,
                remotePlanId:  $remotePlanId,
                customerEmail: $customerEmail,
                returnUrl:     $browserReturn,
                cancelUrl:     $browserCancel,
            );
        } catch (\Throwable $e) {
            \Log::error('PayPal sub checkout: create failed', [
                'intent_uid' => $intent->uid,
                'error'      => $e->getMessage(),
            ]);
            return redirect()->away($merchantReturn)
                ->with('alert-error', trans('paypal::messages.subscription.create_failed', ['error' => $e->getMessage()]));
        }

        $remoteId   = (string) ($resp['id'] ?? '');
        $approveUrl = PayPalApi::linkRel($resp['links'] ?? [], 'approve');

        if (!$remoteId || !$approveUrl) {
            \Log::error('PayPal sub checkout: Init response missing id/approve link', [
                'intent_uid' => $intent->uid,
                'response'   => $resp,
            ]);
            abort(502, 'PayPal did not return a subscription approval URL');
        }

        $meta = is_array($intent->metadata) ? $intent->metadata : [];
        $meta['paypal_subscription_id'] = $remoteId;
        $meta['paypal_plan_id']         = $remotePlanId;
        $intent->metadata = $meta;
        $intent->save();

        return redirect()->away($approveUrl);
    }

    /**
     * Resolve the PayPal Billing Plan ID. Honour intent.metadata first, then fall back to the
     * plan's remote-subscription item (PlanRemoteItem) — the canonical mapping after the
     * `plan_remote_mappings` table was retired in favour of `plan_remote_items`.
     *
     * NOTE: in the current design the host drives remote subscriptions through
     * SubscriptionManagementService::getCheckoutUrl (→ the gateway's
     * getCheckoutUrl), so this controller is a legacy/alt entry; it stays correct +
     * crash-free for any code path that still routes through getCheckoutUrl().
     */
    private function resolveRemotePlanId(PaymentIntent $intent): ?string
    {
        $meta = is_array($intent->metadata) ? $intent->metadata : [];
        if (!empty($meta['remote_plan_id'])) {
            return (string) $meta['remote_plan_id'];
        }

        $plan = $intent->invoice?->order?->orderItems?->first()?->subscription?->plan;
        if (!$plan) {
            return null;
        }

        $sub = \App\Model\PlanRemoteItem::subscriptionFor($plan->id);
        return $sub?->remote_price_id ? (string) $sub->remote_price_id : null;
    }
}

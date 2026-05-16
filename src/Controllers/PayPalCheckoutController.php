<?php

namespace Acelle\Paypal\Controllers;

use Acelle\Paypal\Services\PayPalGateway;
use App\Http\Controllers\Controller;
use App\Library\Facades\Billing;
use App\Model\PaymentIntent;
use Illuminate\Http\Request;

/**
 * Browser lands here after picking PayPal at /subscription/checkout.
 *
 * Branches on whether the intent represents a subscription (has a mapped
 * remote_plan_id) vs a one-off invoice:
 *   - Subscription: POST /v1/billing/subscriptions, stamp paypal_subscription_id
 *                   on intent.metadata, 302 to approve link
 *   - One-off:      POST /v2/checkout/orders, stamp paypal_order_id on metadata,
 *                   302 to approve link
 *
 * Either failure path catches Throwable, logs, and bounces back to the original
 * return_url with `alert-error` flash. Customer never sees a 500 — the existing
 * "Last payment attempt failed" banner on the payment page renders the reason.
 */
class PayPalCheckoutController extends Controller
{
    public function redirect(Request $request, string $intentUid)
    {
        $intent = PaymentIntent::where('uid', $intentUid)->first();
        if (!$intent) {
            abort(404, 'Payment intent not found');
        }

        $intent->load(['invoice.customer', 'paymentGateway', 'invoice.order.orderItems.subscription.plan']);
        $service = Billing::resolveService($intent->paymentGateway);
        if (!$service instanceof PayPalGateway) {
            abort(400, 'Intent is not bound to a PayPal gateway');
        }

        $invoice  = $intent->invoice;
        $customer = $invoice?->customer;
        if (!$customer) {
            abort(400, 'Invoice has no customer');
        }

        $merchantReturn = (string) $request->query('return_url', url('/'));
        // After PayPal redirects browser back, our return controller verifies +
        // commits + then redirects to the original merchant return_url.
        $browserReturn = route('paypal.return', ['intent_uid' => $intent->uid])
            . '?return_url=' . urlencode($merchantReturn);
        $browserCancel = $browserReturn . '&cancel=1';

        $customerEmail = (string) ($invoice->billing_email ?: $customer->email);
        $remotePlanId  = $this->resolveRemotePlanId($intent);

        try {
            if ($remotePlanId) {
                // Subscription branch.
                $resp = $service->createSubscriptionViaCheckout(
                    intentUid:     $intent->uid,
                    remotePlanId:  $remotePlanId,
                    customerEmail: $customerEmail,
                    returnUrl:     $browserReturn,
                    cancelUrl:     $browserCancel,
                );
                $remoteId   = (string) ($resp['id'] ?? '');
                $approveUrl = $this->linkRel($resp['links'] ?? [], 'approve');

                if (!$remoteId || !$approveUrl) {
                    throw new \RuntimeException('PayPal subscription create returned no approve link');
                }
                $this->stampMetadata($intent, [
                    'paypal_subscription_id' => $remoteId,
                    'paypal_plan_id'         => $remotePlanId,
                ]);
            } else {
                // One-off branch.
                $resp = $service->createOrder(
                    intentUid:    $intent->uid,
                    amountMajor:  (float) $intent->amount,
                    currency:     (string) ($intent->currency ?: 'USD'),
                    description:  (string) ($intent->description ?: 'Acelle invoice'),
                    returnUrl:    $browserReturn,
                    cancelUrl:    $browserCancel,
                );
                $remoteId   = (string) ($resp['id'] ?? '');
                $approveUrl = $this->linkRel($resp['links'] ?? [], 'approve')
                           ?: $this->linkRel($resp['links'] ?? [], 'payer-action');

                if (!$remoteId || !$approveUrl) {
                    throw new \RuntimeException('PayPal order create returned no approve link');
                }
                $this->stampMetadata($intent, ['paypal_order_id' => $remoteId]);
            }
        } catch (\Throwable $e) {
            \Log::error('PayPal checkout: create failed', [
                'intent_uid' => $intent->uid,
                'mode'       => $remotePlanId ? 'subscription' : 'one-off',
                'error'      => $e->getMessage(),
            ]);
            $msgKey = $remotePlanId ? 'paypal::messages.subscription.create_failed' : 'paypal::messages.checkout.create_failed';
            return redirect()->away($merchantReturn)
                ->with('alert-error', trans($msgKey, ['error' => $e->getMessage()]));
        }

        return redirect()->away($approveUrl);
    }

    /**
     * Look up the PayPal Billing Plan ID this intent maps to (via PlanRemoteMapping).
     * Returns null for one-off invoices (no subscription order line).
     */
    private function resolveRemotePlanId(PaymentIntent $intent): ?string
    {
        // First, allow the intent.metadata to carry an explicit plan id (used by
        // some upgrade flows that pre-resolve the mapping). Honoured if present.
        $meta = is_array($intent->metadata) ? $intent->metadata : [];
        if (!empty($meta['remote_plan_id'])) {
            return (string) $meta['remote_plan_id'];
        }

        $orderItem = $intent->invoice?->order?->orderItems?->first();
        $plan = $orderItem?->subscription?->plan;
        if (!$plan) {
            return null;
        }

        $mapping = \DB::table('plan_remote_mappings')
            ->where('plan_id', $plan->id)
            ->where('payment_gateway_id', $intent->payment_gateway_id)
            ->first();

        return $mapping?->remote_plan_id ? (string) $mapping->remote_plan_id : null;
    }

    private function stampMetadata(PaymentIntent $intent, array $additions): void
    {
        $meta = is_array($intent->metadata) ? $intent->metadata : [];
        foreach ($additions as $k => $v) {
            $meta[$k] = $v;
        }
        $intent->metadata = $meta;
        $intent->save();
    }

    /** Pull a link by rel from PayPal's HATEOAS links[] block. */
    private function linkRel(array $links, string $rel): ?string
    {
        foreach ($links as $link) {
            if (($link['rel'] ?? null) === $rel) {
                return (string) ($link['href'] ?? '');
            }
        }
        return null;
    }
}

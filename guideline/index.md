# PayPal for Acelle Mail — User Guide

A drop-in plugin that adds **PayPal** (paypal.com) to Acelle as **two payment gateways shipped together**:

- **PayPal** — one-off charges via PayPal **Orders v2**. Customer can pay with their PayPal account OR as a guest with debit/credit card (no PayPal account required on the buyer side).
- **PayPal Subscription** — recurring billing managed by PayPal via **Subscriptions v1**. Customer approves once; PayPal auto-charges each cycle.

Both gateways are powered by the same plugin, share the same form (Client ID + Secret + Environment), and use **pull-only sync** — no public webhook endpoint, no IPN configuration. Enable one or both depending on what your customers need.

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Install the plugin](#2-install-the-plugin)
3. [Enable the plugin](#3-enable-the-plugin)
4. [Pick the gateway(s) you need — one-off, subscription, or both](#4-pick-the-gateways-you-need-one-off-subscription-or-both)
5. [Configure Client ID + Secret + Environment](#5-configure-client-id-secret-environment)
6. [Map your local plans to PayPal Billing Plans (subscription only)](#6-map-your-local-plans-to-paypal-billing-plans-subscription-only)
7. [How customers pay](#7-how-customers-pay)
8. [Subscription state sync](#8-subscription-state-sync)
9. [Sandbox testing](#9-sandbox-testing)
10. [Troubleshooting](#10-troubleshooting)
11. [Uninstall](#11-uninstall)

---

## 1. Requirements

| | Minimum |
|---|---|
| **Acelle Mail** | 4.0.24 or newer |
| **PHP** | 8.1+ (matches Acelle's own minimum) |
| **PayPal account** | A PayPal Business account at [paypal.com](https://www.paypal.com/business) (free signup). A free **Sandbox** is included for testing. |
| **REST API app** | Create one at [developer.paypal.com/dashboard](https://developer.paypal.com/dashboard/) → **Apps & Credentials**. The app produces a **Client ID** and **Secret** — the only two credentials this plugin needs. The same app works for BOTH gateways. |
| **Outbound HTTPS** | Server must reach `api-m.sandbox.paypal.com` (sandbox) and `api-m.paypal.com` (live) on port 443 |
| **Inbound HTTPS** | Your Acelle install must be reachable so customers can be redirected back from PayPal after approve / cancel (`/cashier/paypal/return/{intent_uid}` or `/cashier/paypal-subscription/return/{intent_uid}`) |

You do **not** need:
- A public webhook endpoint or webhook configuration in PayPal's dashboard
- IPN (Instant Payment Notification) — that's a legacy push protocol; this plugin uses the modern pull model
- Cron beyond Acelle's existing scheduler (subscription sync runs inside `php artisan schedule:run`)

---

## 2. Install the plugin

Standard Acelle plugin install — admin → Plugins → upload ZIP.

**Steps**

1. Download the plugin ZIP from CodeCanyon (file name: `acelle-paypal-v1.0.x.zip`).
2. In the admin sidebar, open **System → Plugins**.
3. Click **Install plugin** in the top right.
4. Drop the ZIP into the upload box (or click **Choose File**) and click **Upload & install**.

The progress dialog runs through three steps — *Uploading package → Extracting + writing files → Registering plugin + running install routines* — and finishes in 5–30 seconds.

![Install plugin upload dialog](images/paypal-plugin-01-install-upload-page.png)

After install completes, the **Plugins** list reloads and **PayPal Payment Gateway** appears as **Inactive**:

![Plugins list with PayPal inactive](images/paypal-plugin-02-plugins-list-paypal-inactive.png)

---

## 3. Enable the plugin

Installing only registers the plugin on disk; enable it explicitly so you stay in control of which gateways are available.

**Steps**

1. In the **Plugins** list, find the **PayPal Payment Gateway** card.
2. Click the **⋮** (kebab) menu in the card's top-right and pick **Enable**.

![PayPal row close-up showing the Inactive state](images/paypal-plugin-03-paypal-row-closeup.png)

The card flips to **Active** — and **both** PayPal gateways become available in the gateway picker:

![Plugins list with PayPal active](images/paypal-plugin-04-plugins-list-paypal-active.png)

> **Disable** removes both PayPal gateways from the picker for new gateways but keeps existing `payment_gateways` rows of type `paypal` and `paypal-subscription`. **Delete** rolls back migrations and removes plugin files — see [§11](#11-uninstall).

---

## 4. Pick the gateway(s) you need — one-off, subscription, or both

Now that the plugin is active, both gateway types are available in the **Add Gateway** picker. You can register one of them, or both side by side.

**Steps**

1. In the admin sidebar, open **Plans & Billing → Payment Gateways**.
2. Click **+ Add Gateway** in the top right.

![Payment gateways list — before adding PayPal](images/paypal-plugin-05-payment-gateways-list.png)

3. The gateway picker opens. You'll see **two PayPal entries** — one in each group:

![Select gateway type — both PayPal options visible](images/paypal-plugin-06-select-gateway-type.png)

| Group | Entry | When to use |
|---|---|---|
| **Direct Payment** | **PayPal** | One-off charges. Customer pays once for the selected plan term. The PayPal hosted checkout also exposes a **guest card payment** option, so buyers without a PayPal account can still pay with their debit/credit card. |
| **Remote Subscription** | **PayPal Subscription** | Recurring billing. Customer approves once at PayPal; PayPal auto-charges each cycle. Requires you to also create matching PayPal Billing Plans (see [§6](#6-map-your-local-plans-to-paypal-billing-plans-subscription-only)). |

Pick whichever you need. If you want both, register them separately — each becomes its own row in **Payment Gateways**.

> **Why two gateways and not one?** Acelle's billing layer needs to know at the picker / mapping level whether a gateway hosts recurring subscriptions (Remote Subscription capability) or one-off charges (Direct Payment). PayPal does both but through different APIs (Orders v2 vs Subscriptions v1) — splitting them into two gateway types lets Acelle route customers cleanly and lets you enable only the side you actually need.

---

## 5. Configure Client ID + Secret + Environment

After picking either gateway, you land on the configuration form. **Both gateways share the same form** — same fields, same validation.

![Empty PayPal configuration form](images/paypal-plugin-07-paypal-config-empty.png)

Fill in these fields:

| Field | Value |
|---|---|
| **Gateway Name** | What customers see at checkout (e.g. `Pay with PayPal`, `Subscribe via PayPal`) |
| **Description** | Free-form. Optional. |
| **Client ID** | The `ATD3wd2…` Client ID from your PayPal REST app. Get it from **developer.paypal.com/dashboard** → Apps & Credentials → toggle Sandbox or Live → pick your app. |
| **Client Secret** | The `EJlMTW…` Secret from the same app. Treated as a password and never displayed back after save. |
| **Environment** | `Sandbox` (developer testing with sandbox accounts and play money) or `Live` (real funds movement). |

![Filled PayPal configuration form](images/paypal-plugin-08-paypal-config-filled.png)

> **Same credentials for both gateways.** The same PayPal REST app produces creds usable for Orders v2 AND Subscriptions v1 — you can paste the same Client ID + Secret into both gateways. There's no separate "subscription credential" at PayPal.

> **Client ID and Secret are environment-bound.** A Sandbox Client ID will NOT authenticate against the Live API and vice versa. Mixing them is the most common mistake — if PayPal returns `Authentication failed` after save, double-check the Sandbox/Live toggle in the PayPal dashboard matches the dropdown value here.

Click **Save**. The plugin runs an immediate `POST /v1/oauth2/token` call against the chosen environment as a connection test — if credentials are wrong, you'll see the error inline and the gateway is **not** saved.

> **No webhook URL to register.** Once the gateway is saved you're done — no callback URL to copy into the PayPal dashboard, no `webhook_id` to paste back, no event subscriptions to configure. State syncs in the other direction (Acelle pulls from PayPal on a schedule — see [§8](#8-subscription-state-sync)).

---

## 6. Map your local plans to PayPal Billing Plans (subscription only)

**This section only applies to the PayPal Subscription gateway.** The one-off **PayPal** gateway needs no plan mapping — it charges the local plan's price directly.

For **PayPal Subscription**, you must map each local Acelle plan to a **PayPal Billing Plan** (PayPal's own plan resource).

**Steps**

1. **Create the PayPal Billing Plan first.** Log in at [paypal.com](https://www.paypal.com) → **Pay & Get Paid → Subscriptions → Plans → Create plan**. Pick a product, set the billing cycle (e.g. monthly USD 49.00), save. PayPal gives the plan an id like `P-5ML4271244454362WXNWU5NQ`.
2. **Map it in Acelle.** Open your PayPal Subscription gateway in Acelle → **Plans & Subscriptions → Remote Plan Mapping**. For each local plan you want to offer through PayPal Subscription, pick the corresponding `P-…` plan id from the dropdown (which is fetched live from `GET /v1/billing/plans` at PayPal).
3. **Save the mapping.** Acelle now knows that "local plan X = PayPal plan P-…".

When a customer subscribes to local plan X via the PayPal Subscription gateway, the plugin starts a PayPal subscription against `P-…` and PayPal handles the recurring billing forever after.

> **Why do I have to create plans at PayPal too?** PayPal's recurring billing requires their resource model: a Product → a Plan → a Subscription. Acelle wraps the Subscription end of that and stores the plan-to-plan mapping, but the Plan resource itself must exist at PayPal because PayPal is the merchant of record for recurring charges.

> **Unmapped local plan + customer subscribes:** the gateway throws `no_plan_mapped` and the customer sees a polite error. Always map every local plan you offer through PayPal Subscription before enabling it for customers.

---

## 7. How customers pay

The two gateways follow slightly different flows because PayPal uses different APIs for one-off vs recurring.

### One-off (PayPal gateway)

```
GET /cashier/paypal/checkout/{intent_uid}?return_url=…
```

1. Plugin calls PayPal's `POST /v2/checkout/orders` with the line item + return / cancel URLs.
2. PayPal returns an `approve` URL.
3. 302 to that URL.
4. Customer approves at PayPal — either with their PayPal account or as a **guest with credit/debit card** (the option is exposed automatically in PayPal's hosted checkout in supported countries).
5. PayPal redirects back to `/cashier/paypal/return/{intent_uid}?token=…`.
6. Return handler calls `POST /v2/checkout/orders/{id}/capture` inline. Money lands in your PayPal balance immediately; the `PaymentIntent` flips to `SUCCEEDED`; the local subscription term starts.

### Subscription (PayPal Subscription gateway)

```
GET /cashier/paypal-subscription/checkout/{intent_uid}?return_url=…
```

1. Plugin looks up the local-plan → PayPal-plan mapping ([§6](#6-map-your-local-plans-to-paypal-billing-plans-subscription-only)).
2. Plugin calls `POST /v1/billing/subscriptions` against the mapped `P-…` plan id.
3. PayPal returns an `approve` URL.
4. 302 to that URL.
5. Customer approves at PayPal (must use a PayPal account — recurring billing requires a stored agreement; guest card flow is not available for subscriptions).
6. PayPal redirects back to `/cashier/paypal-subscription/return/{intent_uid}?subscription_id=…`.
7. Return handler stores the PayPal subscription id and marks the local subscription `active`.
8. Subsequent renewals are detected by the periodic sync ([§8](#8-subscription-state-sync)).

For both gateways, the customer can also cancel at PayPal (e.g. via the link in their PayPal account); the periodic sync catches that on the next tick.

---

## 8. Subscription state sync

For the PayPal Subscription gateway, PayPal owns the subscription's lifecycle (renewals, cancellations, failures, refunds). Acelle pulls state on demand.

Three sync triggers:

| Trigger | When | What it does |
|---|---|---|
| **Per-page lazy fetch** | Admin or customer opens a subscription detail page | Calls `GET /v1/billing/subscriptions/{id}` and renders the latest status, next-bill-date, and payment-method preview directly |
| **Periodic sync job** | Hourly by default (Acelle scheduler) | `RemoteSubscriptionSyncService` walks every active remote sub through this gateway, fetches state, and dispatches lifecycle events (`onSubscriptionRenewed`, `onSubscriptionCancelled`, `onPaymentFailed` …) |
| **Manual "Refresh"** | Admin clicks the Refresh button on a subscription page | Forces a fresh fetch bypassing any cache |

Because the architecture is pull-not-push, state lags reality by at most one sync interval (typically minutes). For SaaS billing this is fine — the customer doesn't see the new sub status the literal microsecond PayPal confirms it; they see it on the next page render or the next periodic tick.

The one-off **PayPal** gateway needs no sync — there's no subscription lifecycle to track. Each capture completes inline at the return URL.

---

## 9. Sandbox testing

PayPal Sandbox is fully isolated from Live — separate accounts, separate balances, separate API hosts.

**Steps**

1. Go to [developer.paypal.com/dashboard](https://developer.paypal.com/dashboard/) → **Sandbox → Accounts**. PayPal gives you 2 default sandbox accounts (one business, one personal).
2. Note the **personal** account's email — that's the buyer you'll use to "pay" in test transactions.
3. In your Acelle PayPal gateway(s), choose **Environment = Sandbox** and use the **Sandbox** Client ID / Secret.
4. At checkout, sign in with the personal sandbox account email when redirected to PayPal.

You can create additional sandbox accounts in different currencies / countries from the same dashboard — useful for testing currency conversion and country-restricted scenarios.

For **PayPal Subscription** testing, you also need to **create at least one Sandbox Billing Plan** in your sandbox PayPal business account and map it to a local Acelle plan via the Remote Plan Mapping UI.

> **Sandbox sometimes returns `INTERNAL_SERVICE_ERROR`** on first request after a long quiet period — PayPal's sandbox cold-starts. Retry once.

---

## 10. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| "Authentication failed" on save | Environment ↔ Client ID mismatch | Sandbox creds + Sandbox dropdown; Live creds + Live dropdown — don't mix |
| Customer lands on PayPal error page | Currency unsupported for the buyer's country, or amount below PayPal's per-currency minimum | Check the order currency is in PayPal's supported list (USD, EUR, GBP, JPY, AUD, CAD, etc.) and the amount is above minimum (1¢ for most) |
| Subscription stays "Pending" after approve | The customer cancelled at PayPal's approve page (browser returned but with `?cancel=1`) | Check the PaymentIntent — if the return-handler saw `cancel=1`, the intent is marked CANCELLED. If status is stuck PENDING, the customer closed the tab without approving |
| Customer sees `"This plan is not mapped to a PayPal Billing Plan"` | Local plan not mapped to a PayPal Plan id (subscription gateway only) | Map at PayPal Subscription gateway → Plans & Subscriptions → Remote Plan Mapping ([§6](#6-map-your-local-plans-to-paypal-billing-plans-subscription-only)) |
| Renewals not appearing in Acelle | Sync cron not running, or PayPal subscription was cancelled outside Acelle | Run `php artisan schedule:run` manually; check the sub status at PayPal → Subscriptions |
| `cURL error 28: Operation timed out` | Outbound firewall blocks PayPal API hosts | Open port 443 to `api-m.paypal.com` and `api-m.sandbox.paypal.com` |
| Plugin shows Active but PayPal missing from gateway picker | Stale opcache / view cache | `php artisan optimize:clear` and reload |

For everything else, the plugin's HTTP calls log to `storage/logs/laravel.log` at `info` on success and `error` on failure with the full PayPal response body inline.

---

## 11. Uninstall

1. Open **System → Plugins**.
2. Find **PayPal Payment Gateway**, click the **⋮** menu and pick **Disable**. Both PayPal gateways immediately disappear from the admin gateway picker — existing `payment_gateways` rows of type `paypal` and `paypal-subscription` are kept (so you don't lose audit history).
3. To also remove the plugin files: same menu → **Delete**. This rolls back the plugin's migrations and removes the on-disk folder.

The core Acelle install is left exactly as it was. The PayPal app in your developer dashboard is unaffected — feel free to keep it for later use or delete it from PayPal's dashboard if you no longer need it.

---

## Support

- **Documentation:** this guide + the comment header at the top of every plugin source file
- **Issue tracker / questions:** the CodeCanyon item comments page
- **Direct contact:** see the **Author** column on the CodeCanyon item page

For PayPal account, payout, app-credential, or business-verification questions, please contact PayPal merchant support directly — those are out of scope for this plugin.

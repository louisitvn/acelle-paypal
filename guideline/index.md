# PayPal for Acelle Mail — User Guide

A drop-in plugin that adds **PayPal** (paypal.com) to Acelle as a **one-off payment gateway**:

- **PayPal** — one-off charges via PayPal **Orders v2**. The customer pays with their PayPal account OR as a guest with a debit/credit card (no PayPal account required on the buyer side).

The plugin uses **pull-only** completion — no public webhook endpoint, no IPN configuration. The buyer approves on PayPal's hosted page, is returned to your site, and the charge is captured inline.

> **One-off only.** This gateway charges once per checkout. A subscription plan paid through PayPal is renewed by the customer paying again each cycle — the plugin does **not** auto-charge a saved PayPal wallet, and it does **not** create a PayPal-managed (Subscriptions v1) subscription. If you need hands-off recurring billing, assign a recurring-capable gateway to those plans instead.

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Install the plugin](#2-install-the-plugin)
3. [Enable the plugin](#3-enable-the-plugin)
4. [Add the PayPal gateway](#4-add-the-paypal-gateway)
5. [Configure Client ID, Secret and Environment](#5-configure-client-id-secret-and-environment)
6. [How customers pay](#6-how-customers-pay)
7. [Sandbox testing](#7-sandbox-testing)
8. [Troubleshooting](#8-troubleshooting)
9. [Uninstall](#9-uninstall)

---

## 1. Requirements

| | Minimum |
|---|---|
| **Acelle Mail** | 4.0.24 or newer |
| **PHP** | 8.1+ (matches Acelle's own minimum) |
| **PayPal account** | A PayPal Business account at [paypal.com](https://www.paypal.com/business) (free signup). A free **Sandbox** is included for testing. |
| **REST API app** | Create one at [developer.paypal.com/dashboard](https://developer.paypal.com/dashboard/) → **Apps & Credentials**. The app produces a **Client ID** and **Secret** — the only two credentials this plugin needs. |
| **Outbound HTTPS** | Server must reach `api-m.sandbox.paypal.com` (sandbox) and `api-m.paypal.com` (live) on port 443 |
| **Inbound HTTPS** | Your Acelle install must be reachable so customers can be redirected back from PayPal after approve / cancel (`/cashier/paypal/return/{intent_uid}`) |

You do **not** need:
- A public webhook endpoint or webhook configuration in PayPal's dashboard
- IPN (Instant Payment Notification) — that's a legacy push protocol; this plugin uses the modern pull model
- Any extra cron — a one-off charge completes inline at the return URL, so there is no subscription state to sync

---

## 2. Install the plugin

Standard Acelle plugin install — admin → Plugins → upload ZIP.

**Steps**

1. Download the plugin ZIP from your account's **Plugins** page. (file name: `acelle-paypal-v1.1.x.zip`).
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

The card flips to **Active** — and the **PayPal** gateway becomes available in the gateway picker:

![Plugins list with PayPal active](images/paypal-plugin-04-plugins-list-paypal-active.png)

> **Disable** removes the PayPal gateway from the picker for new gateways but keeps existing `payment_gateways` rows of type `paypal` (so you don't lose audit history). **Delete** rolls back migrations and removes plugin files — see [§9](#9-uninstall).

---

## 4. Add the PayPal gateway

Now that the plugin is active, **PayPal** is available in the **Add Gateway** picker, under **Direct Payment**.

**Steps**

1. In the admin sidebar, open **Plans & Billing → Payment Gateways**.
2. Click **+ Add Gateway** in the top right.

![Payment gateways list — before adding PayPal](images/paypal-plugin-05-payment-gateways-list.png)

3. The gateway picker opens. Pick **PayPal** from the **Direct Payment** group:

![Select gateway type — PayPal under Direct Payment](images/paypal-plugin-06-select-gateway-type.png)

| Group | Entry | What it does |
|---|---|---|
| **Direct Payment** | **PayPal** | One-off charges. The customer pays once for the selected plan term. The PayPal hosted checkout also exposes a **guest card payment** option, so buyers without a PayPal account can still pay with their debit/credit card. |

> **Upgrading from an older version?** Plugin versions before 1.1.0 also registered a second **"PayPal Subscription"** entry (a PayPal-managed, Subscriptions v1 recurring gateway). That capability has been **removed** — PayPal is now one-off only. If a legacy `paypal-subscription` gateway row still exists from an older install, it no longer appears in the picker; migrate those plans to another recurring-capable gateway.

---

## 5. Configure Client ID, Secret and Environment

After picking the gateway, you land on the configuration form.

![Empty PayPal configuration form](images/paypal-plugin-07-paypal-config-empty.png)

Fill in these fields:

| Field | Value |
|---|---|
| **Gateway Name** | What customers see at checkout (e.g. `Pay with PayPal`) |
| **Description** | Free-form. Optional. |
| **Client ID** | The `ATD3wd2…` Client ID from your PayPal REST app. Get it from **developer.paypal.com/dashboard** → Apps & Credentials → toggle Sandbox or Live → pick your app. |
| **Client Secret** | The `EJlMTW…` Secret from the same app. Treated as a password and never displayed back after save. |
| **Environment** | `Sandbox` (developer testing with sandbox accounts and play money) or `Live` (real funds movement). |

![Filled PayPal configuration form](images/paypal-plugin-08-paypal-config-filled.png)

> **Client ID and Secret are environment-bound.** A Sandbox Client ID will NOT authenticate against the Live API and vice versa. Mixing them is the most common mistake — if PayPal returns `Authentication failed` after save, double-check the Sandbox/Live toggle in the PayPal dashboard matches the dropdown value here.

Click **Save**. The plugin runs an immediate `POST /v1/oauth2/token` call against the chosen environment as a connection test — if credentials are wrong, you'll see the error inline and the gateway is **not** saved.

> **No webhook URL to register.** Once the gateway is saved you're done — no callback URL to copy into the PayPal dashboard, no `webhook_id` to paste back, no event subscriptions to configure. Each charge completes inline when the buyer is redirected back.

---

## 6. How customers pay

```
GET /cashier/paypal/checkout/{intent_uid}?return_url=…
```

1. Plugin calls PayPal's `POST /v2/checkout/orders` (intent = CAPTURE) with the line item + return / cancel URLs.
2. PayPal returns an `approve` URL.
3. 302 to that URL.
4. The customer approves at PayPal — either with their PayPal account or as a **guest with a credit/debit card** (the option is exposed automatically in PayPal's hosted checkout in supported countries).
5. PayPal redirects back to `/cashier/paypal/return/{intent_uid}?token=…`.
6. The return handler calls `POST /v2/checkout/orders/{id}/capture` inline. Money lands in your PayPal balance immediately; the `PaymentIntent` flips to `SUCCEEDED`; the local plan term starts.

If the customer clicks **Cancel** at PayPal, they return with `?cancel=1` and the intent is marked failed with a "cancelled at PayPal" reason — the payment page shows the reason so they can try again or pick another method.

There is no ongoing state to sync: a one-off capture is complete the moment it settles at the return URL.

---

## 7. Sandbox testing

PayPal Sandbox is fully isolated from Live — separate accounts, separate balances, separate API hosts.

**Steps**

1. Go to [developer.paypal.com/dashboard](https://developer.paypal.com/dashboard/) → **Sandbox → Accounts**. PayPal gives you 2 default sandbox accounts (one business, one personal).
2. Note the **personal** account's email — that's the buyer you'll use to "pay" in test transactions.
3. In your Acelle PayPal gateway, choose **Environment = Sandbox** and use the **Sandbox** Client ID / Secret.
4. At checkout, sign in with the personal sandbox account email when redirected to PayPal, then approve.

You can create additional sandbox accounts in different currencies / countries from the same dashboard — useful for testing currency conversion and country-restricted scenarios.

> **Sandbox sometimes returns `INTERNAL_SERVICE_ERROR`** on the first request after a long quiet period — PayPal's sandbox cold-starts. Retry once.

---

## 8. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| "Authentication failed" on save | Environment ↔ Client ID mismatch | Sandbox creds + Sandbox dropdown; Live creds + Live dropdown — don't mix |
| Customer lands on PayPal error page | Currency unsupported for the buyer's country, or amount below PayPal's per-currency minimum | Check the order currency is in PayPal's supported list (USD, EUR, GBP, JPY, AUD, CAD, etc.) and the amount is above minimum (1¢ for most) |
| Intent stuck "Pending" after approve | The customer closed the tab without approving, or returned with `?cancel=1` | Check the PaymentIntent — a `cancel=1` return marks it CANCELLED; a stuck PENDING means the buyer never completed approval. They can retry from the payment page |
| `cURL error 28: Operation timed out` | Outbound firewall blocks PayPal API hosts | Open port 443 to `api-m.paypal.com` and `api-m.sandbox.paypal.com` |
| Plugin shows Active but PayPal missing from gateway picker | Stale opcache / view cache | `php artisan optimize:clear` and reload |

For everything else, the plugin's HTTP calls log to `storage/logs/laravel.log` at `info` on success and `error` on failure with the full PayPal response body inline.

---

## 9. Uninstall

1. Open **System → Plugins**.
2. Find **PayPal Payment Gateway**, click the **⋮** menu and pick **Disable**. The PayPal gateway immediately disappears from the admin gateway picker — existing `payment_gateways` rows of type `paypal` are kept (so you don't lose audit history).
3. To also remove the plugin files: same menu → **Delete**. This rolls back the plugin's migrations and removes the on-disk folder.

The core Acelle install is left exactly as it was. The PayPal app in your developer dashboard is unaffected — feel free to keep it for later use or delete it from PayPal's dashboard if you no longer need it.

---

## Support

- **Documentation:** this guide + the comment header at the top of every plugin source file
- Questions & issues: contact **support@acellemail.com**

For PayPal account, payout, app-credential, or business-verification questions, please contact PayPal merchant support directly — those are out of scope for this plugin.

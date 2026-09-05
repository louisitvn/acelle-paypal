# PayPal Payment Gateway for Acelle Mail

[![Plugin version](https://img.shields.io/badge/version-1.3.2-blue)](https://github.com/louisitvn/acelle-paypal/releases)
[![Acelle Mail](https://img.shields.io/badge/Acelle%20Mail-4.0.24%2B-2563eb)](https://acellesend.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](https://www.php.net/)
[![PayPal API](https://img.shields.io/badge/PayPal-Orders%20v2%20%2B%20Subscriptions%20v1-003087)](https://developer.paypal.com/)

This is a **PayPal plugin for [Acelle Mail](https://acellesend.com) — a self-hosted email marketing platform** you install on your own hosting server. The plugin lets an Acelle administrator charge customers through PayPal, both for one-time purchases and for recurring subscriptions when Acelle is run as a **SaaS** business.

It is **free**, and it is a drop-in: upload the ZIP, enable it, paste a Client ID and Secret. No core files are patched, and nothing else in your installation changes.

[![The PayPal payment gateway plugin in the Acelle Mail dashboard, listed with other payment gateways for self-hosted email marketing](https://acelle2026.s3.dualstack.us-east-1.amazonaws.com/acellesend-dashboard-plugins-pay.png)](https://acellesend.com/integrations)

<sub>PayPal alongside the other payment gateways in an Acelle Mail installation's plugin catalog — install it from there, or upload the ZIP by hand.</sub>

---

## About Acelle Mail

[Acelle Mail](https://acellesend.com) is **self-hosted email marketing software**: a complete campaign platform — lists, segmentation, automation, templates, tracking and reporting — that runs on **your own hosting server**, under your own domain, with the full source code included. There is no per-subscriber pricing and no monthly platform fee; the licence is a **one-time purchase**.

Because it is self-hosted, you choose how mail actually leaves the building. Acelle sends through **[Amazon SES](https://acellesend.com/integrations)**, SendGrid, Mailgun, SparkPost, Postmark, Elastic Email or any plain **SMTP** relay — and you can mix several sending servers, rotate between them, and set your own quotas. Sending half a million emails a month through Amazon SES typically costs tens of dollars in provider fees rather than the hundreds a hosted platform would charge for the same volume, and your subscriber data never leaves infrastructure you control.

The **Extended License** goes a step further: it ships a complete **multi-tenant SaaS framework**, so you can run Acelle as your own email marketing service — customer signup, plans, quotas, invoices and subscription billing — and sell to your own users. **That is where this plugin comes in:** it is one of the payment gateways that collects the money.

Learn more: [features](https://acellesend.com/features) · [integrations](https://acellesend.com/integrations) · [pricing](https://acellesend.com/pricing) · [live demo](https://acellesend.com/demo) · [knowledge base](https://acellesend.com/kb)

---

## What this plugin does

It adds **PayPal** to Acelle's payment-gateway picker as a *single* gateway that covers both billing shapes an Acelle SaaS operator needs:

| | How it works | Typical use |
|---|---|---|
| **One-off payments** | PayPal **Orders v2** hosted checkout. The buyer pays once, with a PayPal account **or as a guest with a debit/credit card** — no PayPal account required. | A one-time invoice, a licence, a credit top-up, a manually issued bill. |
| **Provider-managed subscriptions** | PayPal **Subscriptions v1**. The buyer approves once, then **PayPal charges every cycle automatically** against a PayPal Billing Plan you map your local plan to. | Monthly or annual SaaS plans in your Acelle installation. |

Which mode runs is decided **automatically, per checkout**: a one-time invoice becomes a one-off charge, a subscription plan becomes a PayPal subscription. Your administrators do not pick a mode, and buyers never see the distinction.

Also included:

- **Guest card payments** — buyers without a PayPal account still convert.
- **Subscription state sync** — cancellations and lapses on the PayPal side are reflected back into the Acelle subscription.
- **Pull-only completion** — no webhook endpoint, no public callback URL, no HMAC secret ([details below](#no-webhook-required)).
- **Sandbox mode** — a full dress rehearsal before you take real money.
- **One gateway, not two** — you register PayPal once; it serves both one-off invoices and recurring plans.

---

## Requirements

| | Minimum |
|---|---|
| **Acelle Mail** | 4.0.24 or newer |
| **PHP** | 8.1+ (same as Acelle's own minimum) |
| **PayPal account** | A free [PayPal Business account](https://www.paypal.com/business). A sandbox is included for testing. |
| **REST API credentials** | A REST app at [developer.paypal.com](https://developer.paypal.com/dashboard/) → *Apps & Credentials*, which yields a **Client ID** + **Secret** — the only two credentials this plugin needs. |
| **PayPal Billing Plans** | *Subscriptions only.* One Product + Billing Plan per local plan you sell recurring. One-off charges need none. |
| **Outbound HTTPS** | Your server must reach `api-m.paypal.com` (live) and `api-m.sandbox.paypal.com` (sandbox) on port 443. |
| **Inbound HTTPS** | Your Acelle installation must be reachable so buyers can be redirected back from PayPal. |

You do **not** need a public webhook endpoint, and you do **not** need IPN.

---

## Installation

### From your Acelle dashboard (recommended)

1. Download the plugin ZIP.
2. In your Acelle admin, open **System → Plugins**.
3. Click **Install plugin**, drop in the ZIP, then **Upload & install**.
4. Find **PayPal Payment Gateway** in the list, open the **⋮** menu and choose **Enable**.

### From source

```bash
git clone https://github.com/louisitvn/acelle-paypal.git
cd acelle-paypal
git checkout 1.3.2          # always build from a tag
./build.sh /tmp/out         # → /tmp/out/paypal-1.3.2.zip
```

Then upload `paypal-1.3.2.zip` through **System → Plugins** exactly as above.

### From the in-app marketplace

If your installation is connected to the Acelle plugin marketplace, open **Plugins → Install plugin → Connect** and install PayPal from the catalog — no manual download.

---

## Configuration

1. Go to **Plans & Billing → Payment Gateways** and click **+ Add Gateway**.
2. Pick **PayPal**.
3. Fill in:

| Field | Where it comes from |
|---|---|
| **Client ID** | developer.paypal.com → Apps & Credentials → your app |
| **Secret** | the same app (treated as a password; never displayed back after saving) |
| **Environment** | `sandbox` while testing, `live` when you go to production |

4. Save. Use **Test connection** to confirm the credentials are accepted before you expose the gateway to buyers.
5. Assign PayPal to the plans or invoices you want it to serve.

> **Sandbox and live use different credentials.** A live Client ID will not work against the sandbox API, and vice versa — switching environment means switching both values.

---

## Selling subscriptions: mapping PayPal Billing Plans

For recurring billing, PayPal is the merchant of record for the schedule, so each local plan must exist at PayPal as a **Billing Plan**:

1. **Create it at PayPal** — *Pay & Get Paid → Subscriptions → Plans → Create plan*: pick or create a Product, set the billing cycle (for example USD 10.00 monthly), and save. PayPal issues an id like `P-5ML4271244454362WXNWU5NQ`.
2. **Map it in Acelle** — open your PayPal gateway → **Plans & Subscriptions → Remote Plan Mapping**, and match each local plan to its `P-…` counterpart. The dropdown is fetched live from PayPal, so you pick rather than paste.
3. Save. New subscribers on that plan are handed to PayPal, approve once, and are charged automatically each cycle.

One-off invoices need none of this — they bill the invoice amount directly.

---

## What your customers see

1. The customer picks a plan or opens an invoice in your Acelle installation and chooses **PayPal**.
2. They are sent to PayPal's own hosted page — your gateway credentials never reach the browser, and card details never touch your server.
3. They pay with a PayPal balance, a linked bank account, or **a debit/credit card as a guest**.
4. PayPal returns them to your site, the payment is confirmed, and the invoice is marked paid or the subscription is activated.

---

## No webhook required

Most gateway integrations ask you to expose a public callback URL, verify an HMAC signature, and keep that endpoint reachable forever. This plugin does not.

Completion is **pull-based**: when the buyer returns from PayPal, your installation asks PayPal directly what happened, and a background poll acts as a backup if the buyer closes the tab on the way back. That means:

- nothing extra to open in your firewall,
- no webhook secret to rotate or leak,
- no silent breakage when a callback URL changes,
- and it works on installations that sit behind a VPN, a staging gate or basic auth.

---

## Testing with the PayPal sandbox

1. Set **Environment** to `sandbox` and paste your sandbox Client ID and Secret.
2. Create sandbox buyer accounts at [developer.paypal.com](https://developer.paypal.com/dashboard/accounts).
3. Run a full purchase end to end — one-off *and*, if you sell recurring, a subscription against a sandbox Billing Plan.
4. Confirm the invoice is marked paid and the subscription appears active in Acelle.
5. Switch to `live` credentials only once both paths pass.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| *Credentials rejected* | Client ID and Secret belong to different environments, or to a different REST app than the one selected. |
| *Buyer is not returned to your site* | Your installation is not reachable over HTTPS from the buyer's browser. |
| *Subscription is not offered, only a one-off charge* | The local plan has no PayPal Billing Plan mapped — see [mapping](#selling-subscriptions-mapping-paypal-billing-plans). |
| *Payment succeeded at PayPal but the invoice is unpaid* | The buyer never returned; wait for the background poll, or re-check the payment from the invoice. |
| *Cannot reach PayPal* | Outbound HTTPS to `api-m.paypal.com` is blocked on the server. |

A full illustrated walkthrough — every screen, every field — ships with the plugin in [`guideline/index.md`](guideline/index.md).

---

## Uninstalling

**Disable** removes PayPal from the gateway picker but keeps its configuration, so you can turn it back on later. **Delete** rolls back the plugin's migrations and removes its files. Existing paid invoices are historical records and are never touched.

---

## Other payment gateways for Acelle

The same drop-in pattern is available for many providers, so you can bill customers the way your market actually pays: **Stripe**, **Braintree**, **Paddle**, **FastSpring**, **Razorpay** (India — UPI, netbanking, wallets), **PayTR** and **T-Bank** (Turkey), **PayU**, **2C2P** (South-East Asia), **Konnect** (Tunisia), **Paystack** (Africa), **Bao Kim** and **SePay** (Vietnam).

Browse them from the Plugins page of your account, or see [all integrations](https://acellesend.com/integrations).

---

## Support

- **Documentation & knowledge base** — <https://acellesend.com/kb>
- **Blog and guides** — <https://acellesend.com/blog>
- **Contact** — <https://acellesend.com/contact> or support@acellemail.com
- **Bugs in this plugin** — open an issue on this repository.

---

## License

This plugin is provided **free of charge** for use with Acelle Mail installations: use it, modify it for your own installation, and deploy it on as many of your own servers as you like.

Acelle Mail itself is a **commercial product** — the source code is included with the licence you buy, but it is not open-source software, and a licence is required to run it. See [pricing](https://acellesend.com/pricing) for the Regular and Extended (SaaS) licences.

*PayPal is a trademark of PayPal, Inc. This plugin is an independent integration and is not affiliated with or endorsed by PayPal.*

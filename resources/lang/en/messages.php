<?php

return [
    // ── 'paypal' gateway (one-off / direct, Orders v2)
    'gateway' => [
        'name'        => 'PayPal',
        'description' => 'Accept one-off PayPal payments via hosted checkout. Customer can pay with their PayPal account OR as a guest with debit/credit card (no PayPal account required).',
    ],

    // ── 'paypal-subscription' gateway (remote subscription, Subscriptions v1)
    'subscription_gateway' => [
        'name'        => 'PayPal Subscription',
        'description' => 'Recurring subscriptions managed by PayPal. Customer approves once at PayPal; the vendor stores the billing agreement and auto-charges each cycle. Pull-only sync (no webhook required).',
    ],

    'form' => [
        'client_id'         => 'Client ID',
        'client_id_help'    => 'PayPal REST API app Client ID (from your sandbox or live app at developer.paypal.com → My Apps).',
        'client_secret'     => 'Client Secret',
        'client_secret_help'=> 'PayPal REST API app secret. Treated as a password — never displayed back after save.',
        'environment'       => 'Environment',
        'environment_help'  => 'Sandbox = developer testing with sandbox accounts and play money. Live = real funds movement.',

        'credentials_title' => 'Where to find your PayPal credentials',
        'credentials_desc'  => 'Sign in at <a href="https://developer.paypal.com/dashboard/" target="_blank">developer.paypal.com/dashboard</a> → Apps & Credentials → toggle Sandbox or Live → pick your app (or create one). Copy <strong>Client ID</strong> and <strong>Secret</strong> into the fields above.',

        'subscriptions_title' => 'For recurring subscriptions (paypal-subscription gateway)',
        'subscriptions_desc'  => 'Create your Billing Plans inside the PayPal dashboard first (Products & Subscriptions → Plans), then map each local plan to a PayPal plan ID in the Plans & Subscriptions section of this gateway after saving.',

        'pull_only_title'   => 'No webhook needed',
        'pull_only_desc'    => 'Subscription state syncs via periodic pull (every hour by default through RemoteSubscriptionSyncService). One-off captures commit at the return URL inline. You do not need to configure a webhook endpoint in PayPal.',
    ],

    'checkout' => [
        'create_failed'   => 'Could not start PayPal checkout: :error',
        'capture_failed'  => 'PayPal capture failed: :error',
        'cancelled'       => 'User cancelled at PayPal',
    ],

    'subscription' => [
        'create_failed'  => 'Could not start PayPal subscription: :error',
        'cancel_failed'  => 'PayPal subscription cancel failed: :error',
        'no_plan_mapped' => 'This plan is not mapped to a PayPal Billing Plan. Admin must map it before subscribing through PayPal.',
    ],
];

<?php

return [
    // ── 'paypal' gateway (Orders v2 hosted checkout + app-driven auto-charge)
    'gateway' => [
        'name'        => 'PayPal',
        'description' => 'Accept PayPal payments via hosted checkout. Customer can pay with their PayPal account OR as a guest with debit/credit card (no PayPal account required). Saved PayPal wallets can be auto-charged for renewals.',
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
    ],

    'checkout' => [
        'create_failed'   => 'Could not start PayPal checkout: :error',
        'capture_failed'  => 'PayPal capture failed: :error',
        'cancelled'       => 'User cancelled at PayPal',
    ],
];

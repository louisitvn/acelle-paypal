<?php

// Flat dotted keys only — never nested arrays (HARD RULE). Callsites use trans('paypal::messages.gateway.name').
return [
    'gateway.name'        => 'PayPal',
    'gateway.description' => 'Accept PayPal payments via hosted checkout — pay with a PayPal account or as a guest with a debit/credit card. Supports one-off charges AND provider-managed subscriptions (PayPal auto-charges each cycle; map your local plans to PayPal Billing Plans for recurring billing).',

    'form.client_id'          => 'Client ID',
    'form.client_id_help'     => 'PayPal REST API app Client ID (from your sandbox or live app at developer.paypal.com → My Apps).',
    'form.client_secret'      => 'Client Secret',
    'form.client_secret_help' => 'PayPal REST API app secret. Treated as a password — never displayed back after save.',
    'form.environment'        => 'Environment',
    'form.environment_help'   => 'Sandbox = developer testing with sandbox accounts and play money. Live = real funds movement.',
    'form.credentials_title'  => 'Where to find your PayPal credentials',
    'form.credentials_desc'   => 'Sign in at <a href="https://developer.paypal.com/dashboard/" target="_blank">developer.paypal.com/dashboard</a> → Apps & Credentials → toggle Sandbox or Live → pick your app (or create one). Copy <strong>Client ID</strong> and <strong>Secret</strong> into the fields above.',

    'checkout.create_failed'  => 'Could not start PayPal checkout: :error',
    'checkout.capture_failed' => 'PayPal capture failed: :error',
    'checkout.cancelled'      => 'User cancelled at PayPal',
];

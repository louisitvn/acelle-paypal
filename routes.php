<?php

use Illuminate\Support\Facades\Route;

// Plugin's own icon, served straight from storage/.
Route::get('plugins/acelle/paypal/icon.svg', function () {
    $path = storage_path('app/plugins/acelle/paypal/icon.svg');
    abort_unless(file_exists($path), 404);
    return response()->file($path, [
        'Content-Type'  => 'image/svg+xml',
        'Cache-Control' => 'public, max-age=31536000',
    ]);
})->name('plugin.acelle.paypal.icon');

// Two payment gateway types live in this plugin — each gets its own
// checkout/return route pair so the URL discriminates which API to call
// (Orders v2 for 'paypal', Subscriptions v1 for 'paypal-subscription').
// Pull-only design — no webhook routes.
Route::group(['middleware' => ['web']], function () {
    // ── 'paypal' — one-off Orders v2 ─────────────────────────────────
    Route::get('/cashier/paypal/checkout/{intent_uid}',
        [\Acelle\Paypal\Controllers\PayPalCheckoutController::class, 'redirect'])
        ->name('paypal.checkout');

    Route::get('/cashier/paypal/return/{intent_uid}',
        [\Acelle\Paypal\Controllers\PayPalReturnController::class, 'handle'])
        ->name('paypal.return');

    // ── 'paypal-subscription' — recurring Subscriptions v1 ───────────
    Route::get('/cashier/paypal-subscription/checkout/{intent_uid}',
        [\Acelle\Paypal\Controllers\PayPalSubscriptionCheckoutController::class, 'redirect'])
        ->name('paypal-subscription.checkout');

    Route::get('/cashier/paypal-subscription/return/{intent_uid}',
        [\Acelle\Paypal\Controllers\PayPalSubscriptionReturnController::class, 'handle'])
        ->name('paypal-subscription.return');
});

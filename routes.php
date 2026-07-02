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

// This plugin ships ONE payment gateway type — 'paypal', one-off Orders v2.
// It gets its own checkout/return route pair. Pull-only design — no webhook routes.
Route::group(['middleware' => ['web']], function () {
    // ── 'paypal' — one-off Orders v2 ─────────────────────────────────
    Route::get('/cashier/paypal/checkout/{intent_uid}',
        [\Acelle\Paypal\Controllers\PayPalCheckoutController::class, 'redirect'])
        ->name('paypal.checkout');

    Route::get('/cashier/paypal/return/{intent_uid}',
        [\Acelle\Paypal\Controllers\PayPalReturnController::class, 'handle'])
        ->name('paypal.return');
});

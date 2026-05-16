<?php

use Illuminate\Support\Facades\Route;

// Plugin's own icon, served straight from storage/. Referenced by the
// Hook::set('icon_url_…') registration in src/ServiceProvider.php so the
// plugin shows an icon on /rui/admin/plugins. Self-contained — no copy into
// public/, no dependency on main app's AssetController.
Route::get('plugins/acelle/paypal/icon.svg', function () {
    $path = storage_path('app/plugins/acelle/paypal/icon.svg');
    abort_unless(file_exists($path), 404);
    return response()->file($path, [
        'Content-Type'  => 'image/svg+xml',
        'Cache-Control' => 'public, max-age=31536000',
    ]);
})->name('plugin.acelle.paypal.icon');

// Payment-gateway routes. Two customer-facing endpoints:
//   - paypal.checkout — entry from app's payment flow; calls Orders v2 (one-off)
//     or Subscriptions v1 (subscription), then 302s to PayPal's approve URL
//   - paypal.return   — PayPal redirects browser here after approve/cancel;
//     branches on ?subscription_id, ?token, or ?cancel=1
//
// No webhook route — pull-only design. RemoteSubscriptionSyncService polls
// state for recurring renewals; one-off completion is committed inline at
// return time via Orders/Capture call.
Route::group(['middleware' => ['web']], function () {
    Route::get('/cashier/paypal/checkout/{intent_uid}',
        [\Acelle\Paypal\Controllers\PayPalCheckoutController::class, 'redirect'])
        ->name('paypal.checkout');

    Route::get('/cashier/paypal/return/{intent_uid}',
        [\Acelle\Paypal\Controllers\PayPalReturnController::class, 'handle'])
        ->name('paypal.return');
});

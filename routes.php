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

// Client View Groups
Route::group(['middleware' => ['web'], 'namespace' => '\Acelle\Paypal\Controllers'], function () {
    Route::get('plugins/acelle/paypal', 'DashboardController@index');
});

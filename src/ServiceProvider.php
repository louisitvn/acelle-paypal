<?php

namespace Acelle\Paypal;

use Illuminate\Support\ServiceProvider as Base;
use App\Library\Facades\Hook;

class ServiceProvider extends Base
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Define the constants to use in the plugin source (optional)
        defined('PAYPAL_PLUGIN_FULL_NAME') || define('PAYPAL_PLUGIN_FULL_NAME', 'acelle/paypal');
        defined('PAYPAL_PLUGIN_SHORT_NAME') || define('PAYPAL_PLUGIN_SHORT_NAME', 'paypal');

        // Translation file registration.
        //
        // The hook tells AppServiceProvider where the dump-clones live AND
        // makes the file editable through the admin Languages UI. Master file
        // (resources/lang/en/messages.php below) is the source — Language::dump()
        // copies it into storage/app/data/plugins/acelle/paypal/lang/{locale}/
        // and AppServiceProvider then registers `paypal::*` to point at
        // that data/lang folder.
        //
        // ⛔ MUST be in register() (not boot). AppServiceProvider's loop runs
        //    in its own boot phase; if the hook is added in plugin's boot()
        //    it would be invisible to that loop.
        //
        // ⛔ Do NOT also call $this->loadTranslationsFrom() in boot(). Plugin
        //    boot() runs after AppServiceProvider::boot, so the second call
        //    overrides the namespace hint and turns dump-clones into zombie
        //    files (admin UI edits stop working at runtime).
        Hook::add('add_translation_file', function () {
            return [
                'id'                      => '#acelle/paypal_translation_file',
                'plugin_name'             => 'acelle/paypal',
                'file_title'              => 'Translation for acelle/paypal plugin',
                'translation_folder'      => storage_path('app/data/plugins/acelle/paypal/lang/'),
                'translation_prefix'      => 'paypal',
                'file_name'               => 'messages.php',
                'master_translation_file' => realpath(__DIR__ . '/../resources/lang/en/messages.php'),
            ];
        });
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        // Run plugin migrations when the plugin is activated.
        // Place migration files in database/migrations/ inside the plugin folder.
        // Safe to register even if the plugin has no migrations — the folder will just be empty.
        Hook::on('activate_plugin_acelle/paypal', function () {
            \Artisan::call('migrate', [
                '--path' => 'storage/app/plugins/acelle/paypal/database/migrations',
                '--force' => true,
            ]);
        });

        // Register views path. Translations are NOT registered here — handled
        // by the `add_translation_file` hook in register() above.
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'paypal');

        // Register routes file (also defines the icon route used below).
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');

        // Plugin icon — rendered on /rui/admin/plugins. Plugin::getIconUrl()
        // resolves via Hook::perform('icon_url_<plugin_name>'); the route is
        // declared in routes.php and serves storage/app/plugins/acelle/paypal/icon.svg
        // directly. Replace icon.svg in the plugin root with your own to brand it.
        Hook::set('icon_url_acelle/paypal', fn () => route('plugin.acelle.paypal.icon'));

        // Rollback plugin migrations when the plugin is deleted
        Hook::on('delete_plugin_acelle/paypal', function () {
            \Artisan::call('migrate:rollback', [
                '--path' => 'storage/app/plugins/acelle/paypal/database/migrations',
                '--force' => true,
            ]);
        });

        // NOTE: if your plugin needs to ship CSS/JS/config files into the host
        // app's public/ or config/ folder, register them via Laravel's standard
        // $this->publishes([...], 'plugin') here. Plugin::register() runs
        // `vendor:publish --tag=plugin --force` on install, so anything tagged
        // 'plugin' is copied automatically. Most plugins do NOT need this —
        // assets can be served directly from storage/ via plugin-owned routes
        // (see routes.php for the icon-serving pattern).
    }
}

<?php

namespace Acelle\Paypal\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller as BaseController;
use App\Model\Plugin;

class DashboardController extends BaseController
{
    public function index(Request $request)
    {
        // Get the plugin record in the plugin table
        $plugin = Plugin::where('name', 'acelle/paypal')->first();

        // View files are available in the storage/app/plugins/acelle/paypal/resources/views/ folder
        // Remember to use the paypal:: prefix for specifying view
        return view('paypal::index', [
            'plugin' => $plugin,
        ]);
    }
}

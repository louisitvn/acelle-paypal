<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Collapse the retired 'paypal-subscription' driver into the unified 'paypal' driver.
 *
 * The two PayPal drivers (one-off + remote subscription) were merged into a single PayPalGateway
 * that honours both directives. `payment_gateways.type` has NO unique constraint and every
 * FK references `payment_gateways.id` (not `type`) — so a plain type-rename keeps each
 * plan / subscription / payment_intent / payment_method / invoice binding valid; a row that
 * was 'paypal-subscription' now resolves to the merged PayPalGateway with its own gatewayData
 * (incl. the JWE keys). If an instance set up BOTH drivers, the two 'paypal' rows simply
 * coexist — each resolves independently — and an admin can consolidate them by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        // DB::table() auto-applies DB_TABLES_PREFIX — no manual prefix here.
        DB::table('payment_gateways')
            ->where('type', 'paypal-subscription')
            ->update(['type' => 'paypal']);
    }

    public function down(): void
    {
        // Forward-only: once merged we cannot tell which 'paypal' rows were originally the
        // subscription driver. The merged driver handles both directives, so there is nothing
        // to reverse — leaving the rows as 'paypal' is correct.
    }
};

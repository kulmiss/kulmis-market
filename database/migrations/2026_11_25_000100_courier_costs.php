<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the courier charges the shop per city/zone (empty = the same as the
 * customer pays), and what each order cost to deliver: free-delivery orders
 * are checked against it so they never lose money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_zones', function (Blueprint $t) {
            $t->unsignedBigInteger('courier_cost')->nullable()->after('per_kg_fee');    // santim, covers included_grams
            $t->unsignedBigInteger('courier_per_kg')->nullable()->after('courier_cost'); // each extra started kg
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('courier_cost')->default(0)->after('shipping_discount');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_zones', fn (Blueprint $t) => $t->dropColumn(['courier_cost', 'courier_per_kg']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('courier_cost'));
    }
};

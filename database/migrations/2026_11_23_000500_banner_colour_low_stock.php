<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $t) {
            $t->string('color', 12)->nullable(); // announcement bar: green | gold | dark | red
        });
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedInteger('low_stock_alert')->nullable(); // own low-stock number (empty = Settings)
            $t->timestamp('low_stock_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['low_stock_alert', 'low_stock_alerted_at']));
        Schema::table('banners', fn (Blueprint $t) => $t->dropColumn('color'));
    }
};

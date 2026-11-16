<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Demo data can now include flash sales, bundles, contests, challenges and custom orders. */
return new class extends Migration
{
    private const TABLES = ['flash_sales', 'bundles', 'contests', 'challenges', 'custom_requests'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('is_demo')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['is_demo']);
                $t->dropColumn('is_demo');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speed: indexes for the newer reports, contests, challenges and the dashboard
 * (money in per period, shop sales per member, first payments).
 */
return new class extends Migration
{
    private const INDEXES = [
        'membership_purchases' => [['status', 'paid_at']],
        'commissions' => [['type', 'level', 'created_at']],
        'orders' => [['status', 'paid_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $cols) {
                    $t->index($cols);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $cols) {
                    $t->dropIndex($cols);
                }
            });
        }
    }
};

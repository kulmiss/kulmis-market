<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Global admin search: names, references and pairs are looked up by their first letters. */
return new class extends Migration
{
    private const INDEXES = [
        'users' => [['first_name'], ['last_name']],
        'payments' => [['provider_reference'], ['proof_reference']],
        'payout_requests' => [['payout_reference']],
        'signals' => [['pair']],
        'products' => [['name']],
        'orders' => [['first_name']],
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

<?php

use App\Demo\Demo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Demo records carry is_demo = true so they can be hidden or deleted, and never count in real totals. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Demo::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'is_demo')) {
                continue; // tables made later have their own is_demo column
            }
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('is_demo')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        foreach (Demo::TABLES as $table) {
            if (in_array($table, ['badge_bonus_runs', 'badge_bonuses'], true) || ! Schema::hasTable($table)) {
                continue; // dropped with their own tables
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['is_demo']);
                $t->dropColumn('is_demo');
            });
        }
    }
};

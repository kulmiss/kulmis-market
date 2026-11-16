<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The bell reuses the in-app list badges already write to.
        Schema::table('user_notifications', function (Blueprint $t) {
            $t->string('dedupe_key', 80)->nullable();   // the same event never shows twice
            $t->unique(['user_id', 'dedupe_key']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->json('notify_prefs')->nullable();
            $t->unsignedBigInteger('goal_amount')->nullable();   // "Earn X this month" (santim)
        });
        Schema::table('carts', function (Blueprint $t) {
            $t->timestamp('reminded_at')->nullable();
            $t->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $t) {
            $t->dropIndex(['user_id', 'updated_at']);
            $t->dropColumn('reminded_at');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['notify_prefs', 'goal_amount']));
        Schema::table('user_notifications', function (Blueprint $t) {
            $t->dropUnique(['user_id', 'dedupe_key']);
            $t->dropColumn('dedupe_key');
        });
    }
};

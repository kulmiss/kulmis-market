<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly badge bonus (santim). Only the level-1 team badges (Bronze …
 * Diamond) pay every month; every other badge can pay a one-time bonus
 * (0 = none). One run per finished month; one row per member and bonus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->unsignedBigInteger('monthly_bonus')->default(0)->after('rank');
            $table->unsignedBigInteger('one_time_bonus')->default(0)->after('monthly_bonus');
        });
        foreach (['bronze' => 20_000, 'silver' => 60_000, 'gold' => 150_000, 'platinum' => 300_000, 'diamond' => 600_000] as $key => $amount) {
            DB::table('badges')->where('key', $key)->where('rule', 'active_level1')->update(['monthly_bonus' => $amount]);
        }

        Schema::create('badge_bonus_runs', function (Blueprint $table) {
            $table->id();
            $table->date('month');                                   // first day of the paid month
            $table->string('status', 12);                           // paid | skipped
            $table->unsignedBigInteger('revenue')->default(0);      // that month's membership money (after refunds)
            $table->unsignedBigInteger('budget')->default(0);       // most that may go to bonuses
            $table->unsignedBigInteger('wanted')->default(0);       // sum before the budget
            $table->unsignedBigInteger('paid')->default(0);         // sum actually paid
            $table->unsignedInteger('members')->default(0);
            $table->boolean('adjusted')->default(false);            // cut to fit the budget
            $table->string('note', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestamps();
            $table->unique(['month', 'is_demo']);
        });

        Schema::create('badge_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('badge_bonus_runs')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('badge_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->string('kind', 10);                             // monthly | one_time
            $table->unsignedBigInteger('wanted');                   // the badge's bonus
            $table->unsignedBigInteger('amount');                   // after the safety limits
            $table->unsignedBigInteger('reduced_amount')->default(0); // taken back before it was ready (refunds)
            $table->string('limited_by', 12)->nullable();           // team | budget | null
            $table->unsignedBigInteger('team_money')->default(0);   // level-1 membership money that month
            $table->unsignedInteger('team_active')->default(0);     // active level-1 members at month end
            $table->string('status', 12)->default('pending')->index(); // pending | available | reduced
            $table->boolean('on_hold')->default(false);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestamps();
            $table->unique(['user_id', 'month', 'kind', 'badge_id']);
            $table->index(['user_id', 'month']);
        });

        // Month-end counting reads membership history by date and by buyer.
        Schema::table('membership_purchases', function (Blueprint $table) {
            $table->index(['status', 'period_end']);
            $table->index(['user_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('membership_purchases', function (Blueprint $table) {
            $table->dropIndex(['status', 'period_end']);
            $table->dropIndex(['user_id', 'paid_at']);
        });
        Schema::dropIfExists('badge_bonuses');
        Schema::dropIfExists('badge_bonus_runs');
        Schema::table('badges', function (Blueprint $table) {
            $table->dropColumn(['monthly_bonus', 'one_time_bonus']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Badges are rows that Super Admin can edit (name, icon, colour, rule,
 * threshold, on/off) and add from the rules list (App\Badges\BadgeRules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 60);
            $table->string('description', 200)->default('');
            $table->string('icon', 30)->default('award');
            $table->string('color', 7)->default('#c9952b');
            $table->string('rule', 30)->index();
            $table->unsignedBigInteger('threshold');   // people, months, orders, rank, or minor units for money
            $table->unsignedSmallInteger('rank')->default(0); // higher = more prestigious ("highest badge")
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->timestamp('unlocked_at');
            $table->timestamp('seen_at')->nullable(); // in-app celebration shown
            $table->unique(['user_id', 'badge_id']);
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 120);
            $table->string('body', 500)->default('');
            $table->string('url', 255)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'read_at']);
        });

        // Keep thresholds Super Admin had already changed for the old 3 badges.
        $old = DB::table('settings')->whereIn('key', ['badges.bronze', 'badges.silver', 'badges.gold'])->pluck('value', 'key');
        $t = fn (string $k, int $d) => (int) ($old["badges.$k"] ?? $d);
        $now = now();
        $rows = [
            ['bronze', 'Bronze', 'Active members you invited yourself', 'medal', '#b0703c', 'active_level1', $t('bronze', 3), 10],
            ['silver', 'Silver', 'Active members you invited yourself', 'medal', '#8c96a3', 'active_level1', $t('silver', 10), 20],
            ['gold', 'Gold', 'Active members you invited yourself', 'medal', '#c9952b', 'active_level1', $t('gold', 25), 30],
            ['platinum', 'Platinum', 'Active members you invited yourself', 'crown', '#5f7f8c', 'active_level1', 50, 40],
            ['diamond', 'Diamond', 'Active members you invited yourself', 'gem', '#3c7fb8', 'active_level1', 100, 50],
            ['team_25', 'Growing team', 'Active members in your whole team (3 levels)', 'users', '#3f8a4e', 'total_team', 25, 15],
            ['team_100', 'Big family', 'Active members in your whole team (3 levels)', 'network', '#2f7a5b', 'total_team', 100, 28],
            ['team_250', 'Community builder', 'Active members in your whole team (3 levels)', 'network', '#1f6b55', 'total_team', 250, 38],
            ['loyal_3', 'Loyal 3 months', 'Months of membership in a row', 'flame', '#d9663a', 'loyalty_months', 3, 8],
            ['loyal_6', 'Loyal 6 months', 'Months of membership in a row', 'flame', '#c9502a', 'loyalty_months', 6, 16],
            ['loyal_12', 'Loyal 1 year', 'Months of membership in a row', 'calendar-check', '#a8401f', 'loyalty_months', 12, 26],
            ['first_sale', 'First sale', 'Someone bought through your link', 'handshake', '#3f8a4e', 'link_sales', 1, 5],
            ['earned_10k', 'Earned 10,000', 'Total earnings from your team', 'coins', '#c9952b', 'earnings_total', 1_000_000, 12],
            ['earned_50k', 'Earned 50,000', 'Total earnings from your team', 'coins', '#b8862a', 'earnings_total', 5_000_000, 22],
            ['earned_100k', 'Earned 100,000', 'Total earnings from your team', 'trophy', '#a87a26', 'earnings_total', 10_000_000, 32],
            ['earned_500k', 'Earned 500,000', 'Total earnings from your team', 'trophy', '#8f6a20', 'earnings_total', 50_000_000, 42],
            ['top_referrer', 'Top referrer', 'Number 1 referrer of a month', 'star', '#e0a526', 'top_referrer_month', 1, 45],
            ['early_supporter', 'Early supporter', 'One of the first members', 'rocket', '#7c5cbf', 'early_member', 100, 9],
            ['first_order', 'First order', 'Paid orders in the shop', 'shopping-bag', '#b5532b', 'orders_count', 1, 1],
            ['orders_5', 'Regular', 'Paid orders in the shop', 'shopping-bag', '#9c4422', 'orders_count', 5, 3],
            ['orders_10', 'Super shopper', 'Paid orders in the shop', 'heart', '#7f361b', 'orders_count', 10, 6],
        ];
        DB::table('badges')->insert(array_map(fn ($r) => ['key' => $r[0], 'name' => $r[1], 'description' => $r[2], 'icon' => $r[3],
            'color' => $r[4], 'rule' => $r[5], 'threshold' => $r[6], 'rank' => $r[7], 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now], $rows));
        DB::table('settings')->whereIn('key', ['badges.bronze', 'badges.silver', 'badges.gold'])->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('badges');
    }
};

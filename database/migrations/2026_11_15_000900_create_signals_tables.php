<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per person with channel access (guest buyer or member).
        Schema::create('signal_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16);                       // purchase | membership | manual
            $table->string('first_name', 80);
            $table->string('last_name', 80)->default('');
            $table->string('phone', 20)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('status', 16)->default('pending')->index(); // pending | active | ended
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable()->index();
            $table->string('link_token', 64)->unique();         // t.me/<bot>?start=<token>
            $table->unsignedBigInteger('telegram_user_id')->nullable()->index();
            $table->string('invite_link', 255)->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('signal_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_subscription_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->string('pair', 40);                    // BTC/USDT, EUR/USD, XAU/USD
            $table->string('direction', 10);               // long | short
            $table->string('entry', 80);                   // "64,200 – 64,500"
            $table->json('targets');                       // ["65,000", "66,200"]
            $table->string('stop_loss', 40);
            $table->text('notes')->nullable();
            $table->string('status', 10)->default('open')->index(); // open | closed
            $table->string('result', 12)->nullable();      // win | loss | breakeven | cancelled
            $table->string('result_value', 40)->nullable(); // "+3.2%" / "-1R"
            $table->string('result_note', 500)->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
        Schema::dropIfExists('signal_purchases');
        Schema::dropIfExists('signal_subscriptions');
    }
};

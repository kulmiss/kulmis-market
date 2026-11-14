<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('wallet_used')->default(0);
            $table->string('status', 16)->default('pending')->index(); // pending | paid | refunded | cancelled
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('membership_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->timestamp('period_end');
            $table->string('kind', 10); // before | today | after
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['membership_id', 'period_end', 'kind']);
        });

        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('continuous_since');
            $table->unsignedInteger('sequence'); // 1 = first 3 months, 2 = 6 months...
            $table->unsignedBigInteger('amount');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'continuous_since', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rewards');
        Schema::dropIfExists('membership_reminders');
        Schema::dropIfExists('membership_purchases');
    }
};

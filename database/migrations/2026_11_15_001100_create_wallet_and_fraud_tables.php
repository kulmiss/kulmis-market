<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('method', 12);                    // telebirr | bank
            $table->string('account_name', 160);
            $table->text('account_number');                  // encrypted
            $table->string('account_fingerprint', 64)->index(); // hash, to spot shared accounts
            $table->string('bank_name', 120)->nullable();
            $table->string('status', 12)->default('requested')->index(); // requested | on_hold | paid | rejected
            $table->timestamp('due_at')->index();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->string('payout_reference', 120)->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->string('hold_reason', 255)->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 40)->index();             // self_referral_device | self_referral_payment | daily_limit | shared_payout_account | manual
            $table->json('details')->nullable();
            $table->string('status', 12)->default('open')->index(); // open | cleared | confirmed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_flags');
        Schema::dropIfExists('payout_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->morphs('payable');                                   // Order, membership purchase, signals purchase
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);                              // chapa | bank_transfer | telebirr | wallet
            $table->string('tx_ref', 80)->unique();
            $table->unsignedBigInteger('amount');                        // santim
            $table->unsignedBigInteger('fee')->default(0);
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->string('status', 20)->default('pending')->index();   // pending | awaiting_review | paid | failed | rejected | refunded | partially_refunded
            $table->string('checkout_url', 500)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->string('payer_account', 120)->nullable();            // for fraud checks (phone / account used to pay)
            $table->string('proof_path', 255)->nullable();
            $table->string('proof_reference', 120)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->timestamp('paid_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('reason', 500);
            $table->string('method', 20);                                // chapa | manual
            $table->string('provider_reference', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
    }
};

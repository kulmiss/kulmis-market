<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('type', 16); // asset, liability, income, expense, equity
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 60)->index();
            $table->string('description', 255)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->nullableMorphs('reference');
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // amount: integer santim, + debit / − credit. Every entry sums to 0.
        Schema::create('ledger_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ledger_account_id', 'id']);
        });

        // Generic "do this once" keys (webhooks, checkout submits, jobs).
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->string('scope', 60);
            $table->json('result')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('ledger_postings');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};

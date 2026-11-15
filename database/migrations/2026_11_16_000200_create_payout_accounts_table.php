<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A member's saved cash-out account. Changing it starts a 48-hour
        // wait, and the first payout to it needs Super Admin to verify it.
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('method', 12);                       // telebirr | bank
            $table->string('account_name', 160);
            $table->text('account_number');                     // encrypted
            $table->string('account_fingerprint', 64)->index(); // keyed hash, to spot shared accounts
            $table->string('bank_name', 120)->nullable();
            $table->string('status', 12)->default('active')->index(); // active | replaced
            $table->timestamp('usable_after');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('payout_requests', function (Blueprint $table) {
            $table->foreignId('payout_account_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('idempotency_key', 80)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('payout_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_account_id');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
        Schema::dropIfExists('payout_accounts');
    }
};

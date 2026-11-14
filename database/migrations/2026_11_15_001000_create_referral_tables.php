<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique();
            $table->timestamp('referral_code_changed_at')->nullable();
            $table->foreignId('referrer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('referred_at')->nullable();
            $table->string('signup_device_hash', 64)->nullable()->index();
            $table->index(['referrer_id', 'created_at']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('referrer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('referral_code', 16)->nullable();
        });
        Schema::table('signal_subscriptions', function (Blueprint $table) {
            $table->foreignId('referrer_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->morphs('source');                       // Order | MembershipPurchase | SignalPurchase
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('type', 12);                     // membership | signals | products
            $table->unsignedBigInteger('base_amount');
            $table->unsignedSmallInteger('rate_bps');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('reversed_amount')->default(0);
            $table->string('status', 12)->index();          // pending | available | reversed | skipped
            $table->string('skip_reason', 40)->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('released_at')->nullable();
            $table->boolean('on_hold')->default(false);     // set by the fraud queue (PR 12)
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'level']);
            $table->index(['recipient_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::table('signal_subscriptions', fn (Blueprint $t) => $t->dropConstrainedForeignId('referrer_user_id'));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('referrer_user_id');
            $t->dropColumn('referral_code');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['referrer_id', 'created_at']);
            $t->dropConstrainedForeignId('referrer_id');
            $t->dropColumn(['referral_code', 'referral_code_changed_at', 'referred_at', 'signup_device_hash']);
        });
    }
};

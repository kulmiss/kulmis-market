<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_gifts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 24)->unique();                // unguessable, in the receiver's link
            $t->unsignedBigInteger('amount');
            $t->string('status', 16)->default('pending');    // pending | paid | redeemed | refunded
            $t->foreignId('giver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('giver_name', 80);
            $t->string('giver_phone', 20);
            $t->string('giver_email', 160)->nullable();
            $t->string('receiver_name', 80);
            $t->string('receiver_contact', 160);             // phone or email
            $t->string('message', 300)->nullable();
            $t->foreignId('referrer_user_id')->nullable()->constrained('users')->nullOnDelete(); // whose team they join
            $t->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('redeemed_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
            $t->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_gifts');
    }
};

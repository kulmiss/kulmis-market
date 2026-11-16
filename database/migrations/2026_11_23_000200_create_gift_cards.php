<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $t) {
            $t->id();
            $t->string('code_hash', 64)->nullable()->unique(); // the code itself is never stored
            $t->string('last4', 4)->nullable();
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('balance')->default(0);
            $t->string('status', 12)->default('pending');      // pending | active | used | disabled | refunded
            $t->string('buyer_name', 80);
            $t->string('buyer_phone', 20)->nullable();
            $t->string('buyer_email', 160)->nullable();
            $t->string('recipient_name', 80)->nullable();
            $t->string('recipient_contact', 160)->nullable();
            $t->string('message', 300)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // sold in the shop by staff
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('gift_card_used')->default(0);
        });
        Schema::table('carts', function (Blueprint $t) {
            $t->foreignId('gift_card_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carts', fn (Blueprint $t) => $t->dropConstrainedForeignId('gift_card_id'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('gift_card_used'));
        Schema::dropIfExists('gift_cards');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('deposit_amount')->default(0);  // to pay first (pre-orders, custom orders)
            $t->unsignedBigInteger('deposit_paid')->default(0);
            $t->timestamp('deposit_paid_at')->nullable();
        });
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedSmallInteger('preorder_deposit_bps')->nullable(); // set = pre-order; share paid now
            $t->date('preorder_ships_on')->nullable();
        });
        Schema::create('custom_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 80);
            $t->string('phone', 20);
            $t->string('email', 160)->nullable();
            $t->string('city', 80);
            $t->string('address', 500);
            $t->text('description');
            $t->json('photos')->nullable();
            $t->unsignedBigInteger('budget')->nullable();
            $t->string('status', 12)->default('new')->index(); // new | quoted | declined | ordered
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('decline_reason', 300)->nullable();
            $t->timestamps();
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->unsignedBigInteger('deposit_bps')->nullable(); // pre-order share paid first
        });
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('winback_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('winback_sent_at'));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('deposit_bps'));
        Schema::dropIfExists('custom_requests');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['preorder_deposit_bps', 'preorder_ships_on']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['deposit_amount', 'deposit_paid', 'deposit_paid_at']));
    }
};

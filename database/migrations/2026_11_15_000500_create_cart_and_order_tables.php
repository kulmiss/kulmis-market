<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->timestamps();
            $table->unique(['cart_id', 'product_id', 'product_variant_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80)->default('');
            $table->string('phone', 20)->index();
            $table->string('email')->nullable();
            $table->string('city', 80);
            $table->string('address', 500);
            $table->unsignedBigInteger('shipping_zone_id')->nullable();
            $table->unsignedBigInteger('courier_id')->nullable();
            $table->string('tracking_number', 80)->nullable();
            $table->string('status', 20)->default('pending_payment')->index();
            $table->boolean('is_member_order')->default(false);
            $table->unsignedBigInteger('list_subtotal');
            $table->unsignedBigInteger('member_discount')->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->unsignedBigInteger('coupon_discount')->default(0);
            $table->unsignedBigInteger('merchandise_total');
            $table->unsignedBigInteger('shipping')->default(0);
            $table->unsignedBigInteger('shipping_discount')->default(0);
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('wallet_used')->default(0);
            $table->unsignedBigInteger('payment_fee')->default(0);
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->string('checkout_key', 64)->unique();
            $table->string('device_hash', 64)->nullable()->index();
            $table->string('notes', 1000)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 300);
            $table->string('sku', 64)->nullable();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('list_unit_price');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->unsignedBigInteger('line_total');
            $table->boolean('made_to_order')->default(false);
            $table->unsignedSmallInteger('making_days')->nullable();
            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from', 20)->nullable();
            $table->string('to', 20);
            $table->string('note', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('notified')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 20)->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['coupon_redemptions', 'order_status_history', 'order_items', 'orders', 'cart_items', 'carts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wishlist, "tell me when it's back" alerts and saved delivery addresses. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'product_id']);
        });

        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('contact', 160);                 // normalised phone or lower-case email
            $table->string('channel', 10);                  // sms | email
            $table->timestamp('notified_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['product_id', 'product_variant_id', 'contact']);
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->default('Home');
            $table->string('first_name', 80);
            $table->string('last_name', 80)->default('');
            $table->string('phone', 20);
            $table->string('city', 80);
            $table->string('address', 500);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('stock_alerts');
        Schema::dropIfExists('wishlist_items');
    }
};

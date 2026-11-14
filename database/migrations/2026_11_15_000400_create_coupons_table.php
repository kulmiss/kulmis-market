<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();            // stored upper-case
            $table->string('type', 10);                       // percent | fixed
            $table->unsignedBigInteger('value');              // bps for percent, santim for fixed
            $table->unsignedBigInteger('min_subtotal')->default(0);
            $table->unsignedBigInteger('max_discount')->nullable(); // cap for percent coupons
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_customer')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('members_only')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_sales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('price');                    // guest price during the sale (santim)
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->unsignedInteger('stock_limit')->nullable();     // pieces at the sale price; null = no limit
            $t->unsignedInteger('sold')->default(0);
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['is_active', 'starts_at', 'ends_at']);
            $t->index(['product_id', 'product_variant_id']);
        });

        Schema::create('bundles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 140)->unique();
            $t->string('description', 500)->nullable();
            $t->unsignedBigInteger('price');                    // guest price of one full set (santim)
            $t->boolean('is_active')->default(true);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
        });

        Schema::create('bundle_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->index('product_id');
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('bundle_discount')->default(0)->after('member_discount');
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->unsignedBigInteger('flash_sale_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropIndex(['flash_sale_id']);
            $t->dropColumn('flash_sale_id');
        });
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('bundle_discount'));
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('bundles');
        Schema::dropIfExists('flash_sales');
    }
};

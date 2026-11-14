<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 80)->unique();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('short_description', 300)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('price');                    // santim, guest price
            $table->unsignedBigInteger('cost_materials')->default(0); // admin-only
            $table->unsignedBigInteger('cost_labour')->default(0);
            $table->unsignedBigInteger('cost_packaging')->default(0);
            $table->unsignedInteger('weight_grams')->default(0);
            $table->string('stock_mode', 16)->default('stock');     // stock | made_to_order
            $table->unsignedInteger('stock')->nullable();           // when the product has no variants
            $table->unsignedSmallInteger('making_days')->nullable();
            $table->boolean('is_featured')->default(false)->index();
            $table->boolean('is_members_only')->default(false)->index();
            $table->timestamp('release_at')->nullable()->index();   // members see it early_access_hours before
            $table->string('status', 16)->default('draft')->index(); // draft | active | archived
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('product_tag', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'tag_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64)->nullable()->unique();
            $table->string('name', 120);                     // "Large / Red"
            $table->json('options')->nullable();             // {"size":"L","colour":"Red"}
            $table->unsignedBigInteger('price')->nullable(); // overrides product price
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path', 255);        // base path, e.g. products/12/ab12cd
            $table->json('formats');            // ["avif","webp","jpg"]
            $table->json('widths');             // [400,800,1600]
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('alt', 200)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_tag');
        Schema::dropIfExists('products');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('categories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->json('cities');                                  // ["Addis Ababa", "Adama"]
            $table->unsignedBigInteger('base_fee');                  // santim, covers included_grams
            $table->unsignedInteger('included_grams')->default(1000);
            $table->unsignedBigInteger('per_kg_fee')->default(0);    // each extra started kg
            $table->unsignedSmallInteger('eta_days_min')->nullable();
            $table->unsignedSmallInteger('eta_days_max')->nullable();
            $table->boolean('is_default')->default(false);           // catch-all for unlisted cities
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);                             // EMS, HudHud, ...
            $table->string('phone', 30)->nullable();
            $table->string('tracking_url', 255)->nullable();         // https://.../track?n={number}
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
        Schema::dropIfExists('shipping_zones');
    }
};

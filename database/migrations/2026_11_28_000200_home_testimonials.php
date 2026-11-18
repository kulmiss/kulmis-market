<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What customers said, typed in by the Super Admin. Shown only after the
 * "confirmed real" tick; a photo only with the person's permission.
 * Demo ones (is_demo) show only while "Show demo data" is on, marked "Demo".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->string('city', 80)->nullable();
            $t->string('text', 600);
            $t->string('photo_path', 200)->nullable();
            $t->boolean('photo_ok')->default(false);       // the person said yes to showing their photo
            $t->boolean('confirmed_real')->default(false); // a real customer said this
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('position')->default(0);
            $t->boolean('is_demo')->default(false)->index();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::table('home_media', function (Blueprint $t) {
            $t->boolean('is_demo')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('home_media', fn (Blueprint $t) => $t->dropColumn('is_demo'));
        Schema::dropIfExists('testimonials');
    }
};

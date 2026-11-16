<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('kind', 12)->default('physical')->index(); // physical | digital | addon
            $t->boolean('member_discount')->default(true);        // false = members pay the normal price
            $t->timestamp('booking_at')->nullable();              // add-ons: the date of the session/event
        });
        Schema::create('product_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 8);                 // file | video
            $t->string('title', 160);
            $t->string('path', 255)->nullable();   // private disk, never public
            $t->unsignedBigInteger('size')->nullable();
            $t->text('video_url')->nullable();     // encrypted private link
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestamps();
        });
        Schema::create('download_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_file_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->unsignedSmallInteger('downloads')->default(0);
            $t->unsignedSmallInteger('max_downloads');
            $t->timestamp('expires_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_grants');
        Schema::dropIfExists('product_files');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['kind', 'member_discount', 'booking_at']));
    }
};

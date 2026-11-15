<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Editable texts: Terms, Privacy, Refund & shipping, Membership terms, Referral rules...
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title', 200);
            $table->longText('body');                 // Markdown
            $table->string('seo_description', 300)->nullable();
            $table->boolean('is_published')->default(true);
            $table->boolean('in_footer')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('placement', 40)->default('home');  // home | shop | membership | signals | top_bar
            $table->string('title', 160);
            $table->string('text', 300)->nullable();
            $table->string('link_url', 255)->nullable();
            $table->string('link_text', 60)->nullable();
            $table->string('image_path', 255)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
        Schema::dropIfExists('pages');
    }
};

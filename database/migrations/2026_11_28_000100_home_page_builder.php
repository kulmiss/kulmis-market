<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home page builder. Each row is one version of the home page:
 * - draft: the one being edited (at most one),
 * - scheduled: waits for publish_at (at most one),
 * - published: every publish is kept; the newest is the live page.
 * Pictures and videos for it live in home_media (re-encoded, no metadata).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_page_versions', function (Blueprint $t) {
            $t->id();
            $t->string('status', 12)->index();
            $t->json('sections');
            $t->json('seo')->nullable();
            $t->string('note', 200)->nullable();
            $t->string('template', 40)->nullable();
            $t->unsignedBigInteger('restored_from_id')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->timestamp('publish_at')->nullable();
            $t->timestamp('published_at')->nullable()->index();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('home_media', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 8);               // image | video
            $t->string('path', 200);             // image: base path without -{width}.{format}; video: the file
            $t->json('formats');
            $t->json('widths');
            $t->unsignedInteger('width')->default(0);
            $t->unsignedInteger('height')->default(0);
            $t->unsignedInteger('bytes')->default(0);
            $t->string('alt', 200)->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_media');
        Schema::dropIfExists('home_page_versions');
    }
};

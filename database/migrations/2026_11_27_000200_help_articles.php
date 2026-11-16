<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help articles: the defaults live in resources/help/*.md. A row here is the
 * Super Admin's own version of one (same slug), a hidden default, or a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 80)->unique();
            $t->string('topic', 60);
            $t->string('title', 160);
            $t->text('body');
            $t->string('feature', 120)->nullable();   // shown only while these switches are on (comma list)
            $t->unsignedSmallInteger('position')->default(50);
            $t->boolean('is_published')->default(true);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('help_feedback', function (Blueprint $t) {
            $t->string('slug', 80)->primary();
            $t->unsignedInteger('yes')->default(0);
            $t->unsignedInteger('no')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_feedback');
        Schema::dropIfExists('help_articles');
    }
};

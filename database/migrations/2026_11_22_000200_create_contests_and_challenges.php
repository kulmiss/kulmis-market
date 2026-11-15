<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contests', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('description', 500)->nullable();
            $t->string('rule', 30);                      // new_paying_members | shop_sales
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->json('prizes');                          // [place 1, place 2, …] in santim (fixed pool)
            $t->unsignedInteger('min_score')->default(1);
            $t->string('status', 12)->default('active'); // active | cancelled | paid
            $t->unsignedBigInteger('paid_total')->default(0);
            $t->boolean('was_cut')->default(false);      // prizes cut to fit the budget
            $t->timestamp('paid_at')->nullable();
            $t->json('result')->nullable();              // final places (first name, score, prize)
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'ends_at']);
        });

        Schema::create('challenges', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->unsignedSmallInteger('target');          // new paying members (level 1) in one month
            $t->unsignedBigInteger('reward');            // santim, per member who makes it
            $t->unsignedBigInteger('monthly_cap')->nullable(); // most paid for this challenge in one month
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('challenge_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $t->date('month');
            $t->unsignedInteger('members')->default(0);
            $t->unsignedBigInteger('paid')->default(0);
            $t->boolean('was_cut')->default(false);
            $t->timestamps();
            $t->unique(['challenge_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_runs');
        Schema::dropIfExists('challenges');
        Schema::dropIfExists('contests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New payouts (first-payment bonus, contest prizes, team challenges,
     * comeback reward) share one table and one life:
     * on the way (14 days) → ready to use, held while a fraud check is open,
     * taken back on a refund.
     */
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);                       // first_payment | contest | challenge | comeback
            $t->unsignedBigInteger('amount');
            $t->unsignedBigInteger('reversed_amount')->default(0);
            $t->string('status', 12)->default('pending');  // pending | available | reversed
            $t->boolean('on_hold')->default(false);
            $t->timestamp('available_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->nullableMorphs('source');                  // what paid for it (membership purchase, contest…)
            $t->string('dedupe_key', 80)->unique();        // never twice for the same thing
            $t->string('description', 255);
            $t->boolean('is_demo')->default(false)->index();
            $t->timestamps();
            $t->index(['status', 'available_at']);
            $t->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};

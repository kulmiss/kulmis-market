<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('endpoint_hash', 64)->unique();
            $t->text('endpoint');
            $t->string('device', 80)->nullable();   // "Android · Chrome", shown in the list
            $t->timestamp('last_used_at')->nullable();
            $t->unsignedSmallInteger('failures')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};

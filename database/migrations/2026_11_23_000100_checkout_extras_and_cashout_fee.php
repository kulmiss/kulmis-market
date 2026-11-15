<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $t) {
            $t->json('extras')->nullable();                  // ["gift_wrap", "express", "insurance"]
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->json('extras')->nullable();                  // [{key, label, amount}]
            $t->unsignedBigInteger('extras_total')->default(0);
        });
        Schema::table('payout_requests', function (Blueprint $t) {
            $t->unsignedBigInteger('fee')->default(0);       // kept by the shop; the member gets amount − fee
        });
    }

    public function down(): void
    {
        Schema::table('payout_requests', fn (Blueprint $t) => $t->dropColumn('fee'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['extras', 'extras_total']));
        Schema::table('carts', fn (Blueprint $t) => $t->dropColumn('extras'));
    }
};

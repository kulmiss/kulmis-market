<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_kit_items', function (Blueprint $t) {
            $t->id();
            $t->string('title', 120);
            $t->string('target', 20);                 // shop | membership | signals
            $t->text('text');                         // {name} {code} {link}
            $t->string('image_path', 255)->nullable(); // public disk
            $t->unsignedSmallInteger('position')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        $now = now();
        foreach ([
            ['Our shop', 'shop', "Handmade in Ethiopia 🇪🇹 Beautiful baskets, coffee sets and more. Have a look: {link}\nUse my code {code} 🙏"],
            ['Membership', 'membership', "I'm a member of Kulmis Market: lower prices on every product, free delivery and a team that earns together. Join with my code {code}: {link}"],
            ['Trading signals', 'signals', 'Members get our private trading signals channel. See the results and join with my code {code}: {link}'],
        ] as $i => [$title, $target, $text]) {
            DB::table('share_kit_items')->insert(['title' => $title, 'target' => $target, 'text' => $text, 'position' => $i,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('share_kit_items');
    }
};

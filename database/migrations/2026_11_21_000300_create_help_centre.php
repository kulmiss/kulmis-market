<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $t) {
            $t->id();
            $t->string('category', 60)->nullable();
            $t->string('question', 200);
            $t->text('answer');
            $t->unsignedSmallInteger('position')->default(0);
            $t->boolean('is_published')->default(true);
            $t->timestamps();
        });
        Schema::create('contact_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 80);
            $t->string('contact', 160);              // phone or email
            $t->string('topic', 40)->nullable();
            $t->text('message');
            $t->string('status', 12)->default('new')->index(); // new | answered | spam
            $t->string('ip_hash', 64)->nullable();
            $t->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('answered_at')->nullable();
            $t->timestamps();
        });

        // Starter answers in easy English. Edit or delete them in Admin → Help centre.
        $now = now();
        $rows = [
            ['Orders', 'How do I pay?', 'At checkout you see our Telebirr and bank details. Pay, then upload a photo of the receipt. We check it and confirm your order, usually the same day.'],
            ['Orders', 'How long does delivery take?', 'In Addis Ababa usually 1–3 days. Other cities 3–7 days. Made-to-order pieces show their making time on the product page.'],
            ['Orders', 'Where is my order?', 'Open "Track my order" and type your order number and phone. Signed-in customers also see every order under Account → Orders.'],
            ['Membership', 'What do members get?', 'A lower price on every product, free delivery above the amount shown on the membership page, the private signals channel and a referral link to earn from your team.'],
            ['Membership', 'What happens if I forget to renew?', 'You get a reminder before the end. After the end there are a few grace days; then member prices and earnings stop until you pay again.'],
            ['Earnings', 'When can I use my earnings?', 'New earnings are "on the way" for 14 days (in case of a refund). Then they move to "ready to use": spend them in the shop or ask for a cash-out.'],
            ['Earnings', 'How do I cash out?', 'Go to Account → Wallet, add your Telebirr or bank account once, and ask for a cash-out. We send it after a quick check.'],
            ['Signals', 'How do I join the signals channel?', 'Members get a personal Telegram link under Account → Signals. Open it in Telegram. It works for your Telegram account only.'],
        ];
        foreach ($rows as $i => [$cat, $q, $a]) {
            DB::table('faqs')->insert(['category' => $cat, 'question' => $q, 'answer' => $a, 'position' => $i, 'is_published' => true,
                'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('faqs');
    }
};

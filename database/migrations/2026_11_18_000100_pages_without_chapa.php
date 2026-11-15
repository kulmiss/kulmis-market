<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Chapa is no longer used: update the default page texts (only sentences that were never edited). */
return new class extends Migration
{
    private const REPLACE = [
        'You can pay through Chapa (Telebirr, CBE Birr, M-Pesa, cards and other methods Chapa offers), by bank or Telebirr transfer with a receipt, or from your wallet balance. We never see or store your card or mobile-money PIN.' => 'You can pay by Telebirr or bank transfer and send us the receipt, or pay from your wallet balance. We confirm transfers by hand, usually within a few hours. We never ask for your mobile-money PIN or bank password.',
        '- **When you pay:** payment status and references from Chapa, or the receipt you upload. We never receive your card details or PIN.' => '- **When you pay:** the receipt you upload and its transaction reference. We never ask for your PIN or bank password.',
        'Only with the services needed to serve you: the payment provider (Chapa), couriers (name, phone and address for delivery), and SMS, email and Telegram providers for messages.' => 'Only with the services needed to serve you: couriers (name, phone and address for delivery), and SMS, email and Telegram providers for messages.',
        '- Refunds go back the way you paid: through Chapa, by transfer, or to your wallet if you paid from it.' => '- Refunds go back the way you paid: by transfer, or to your wallet if you paid from it.',
    ];

    public function up(): void
    {
        foreach (DB::table('pages')->get(['id', 'body']) as $page) {
            $body = strtr((string) $page->body, self::REPLACE);
            if ($body !== $page->body) {
                DB::table('pages')->where('id', $page->id)->update(['body' => $body, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};

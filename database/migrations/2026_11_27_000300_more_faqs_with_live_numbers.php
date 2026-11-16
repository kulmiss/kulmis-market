<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FAQ: numbers come from the settings ({pending_days}, {cashout_min}…), and 12
 * more of the most likely questions (20 in all). Only answers nobody edited
 * are changed; questions you already have are not added twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('faqs')->where('question', 'When can I use my earnings?')
            ->where('answer', 'New earnings are "on the way" for 14 days (in case of a refund). Then they move to "ready to use": spend them in the shop or ask for a cash-out.')
            ->update(['answer' => 'New earnings are "on the way" for {pending_days} days (in case of a refund). Then they move to "ready to use": spend them in the shop or ask for a cash-out.']);

        $now = now();
        $rows = [
            ['Membership', 'How much is the membership?', 'It costs {membership_price} per month. There is no yearly plan.'],
            ['Membership', 'How do I renew?', 'Open your account and tap Renew. Pay from your wallet or by Telebirr / bank transfer with a receipt.'],
            ['Earnings', 'How much can I earn?', 'For each person you invited who pays {membership_price}, you earn {l1_amount} every month they stay. You also earn a smaller share from the next levels. There is no fixed income: you earn only when real people pay.'],
            ['Earnings', 'Why did my earning go down?', 'A payment from your team was given back (refunded), so its earnings were taken back. In Earnings the line shows "Taken back".'],
            ['Earnings', 'Can I see who is in my team?', 'You see the first names of the people you invited. Deeper levels show without names, to protect their privacy.'],
            ['Earnings', 'What is the smallest cash-out?', '{cashout_min}. We send it within {payout_days} days.'],
            ['Earnings', 'Why is my cash-out held?', 'The first cash-out of a new account is checked once, and so is a cash-out to an account whose name does not match yours. Your money stays safe while we check.'],
            ['Earnings', 'Can I change my cash-out account?', 'Yes, in Wallet. For your safety the new account can be used after {account_hold_hours} hours, and we send you a message.'],
            ['Orders', 'Can I pay from my wallet?', 'Yes. At checkout tick "Use my wallet". If it is not enough, you pay the rest by transfer.'],
            ['Orders', 'Can I get my money back?', 'If an item arrives damaged or wrong, tell us within a week of delivery with photos. You can cancel before the order ships.'],
            ['Account', 'I forgot my password', 'On the sign-in page tap "Forgot password?" and follow the steps. We send a code to your phone or email.'],
            ['Account', 'Someone else used my account', 'Change your password, tap "Sign out everywhere" in Security and contact us right away.'],
        ];
        $pos = (int) DB::table('faqs')->max('position');
        foreach ($rows as [$cat, $q, $a]) {
            if (DB::table('faqs')->where('question', $q)->exists()) {
                continue;
            }
            DB::table('faqs')->insert(['category' => $cat, 'question' => $q, 'answer' => $a, 'position' => ++$pos, 'is_published' => true,
                'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void {}
};

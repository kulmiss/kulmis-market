<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Support\Money;
use App\Support\PageTextUpdates;
use App\Support\Settings;
use Illuminate\Database\Seeder;

/**
 * Default texts. Edit them in Admin → Pages & banners. Existing pages are
 * never overwritten. Placeholders like {price} are written out from the
 * current settings when the page is first created.
 */
class PagesSeeder extends Seeder
{
    public function run(): void
    {
        $s = fn (string $k) => Settings::int($k);
        $m = fn (string $k) => Money::format(Settings::int($k));
        $p = fn (string $k) => rtrim(rtrim(number_format(Settings::int($k) / 100, 2), '0'), '.').'%';
        $site = Settings::string('site.name');
        $email = Settings::string('site.support_email');
        $phone = Settings::string('site.support_phone');

        $pages = [
            ['terms', 'Terms of use and sale', 1, <<<MD
These terms apply when you use {$site} (the "shop") and when you buy from us. By placing an order or creating an account you accept them.

## Who we are
{$site} sells handcrafted products made in Ethiopia and offers a trading-signals service and a paid membership. Contact us at {$phone} or {$email}.

## Orders and prices
- All prices are in Ethiopian Birr (ETB) and include the price of the product. Shipping is shown separately at checkout.
- Members see and pay the member price. Coupons are applied after the member discount.
- An order is confirmed when it is paid. Unpaid orders are cancelled after {$s('orders.unpaid_cancel_hours')} hours.
- Handmade pieces vary slightly in colour, size and pattern. This is part of their character, not a defect.
- Made-to-order items show an estimated making time. We tell you if it changes.

## Payment
You can pay by Telebirr or bank transfer and send us the receipt, or pay from your wallet balance. We confirm transfers by hand, usually within a few hours. We never ask for your mobile-money PIN or bank password.

## Delivery
We ship all over Ethiopia with partner couriers. Delivery times are estimates. You can follow your order at any time on the tracking page with your order number and phone.

## Accounts
Keep your password private. You are responsible for activity on your account. We may suspend accounts used for fraud, abuse or to break these terms.

## Trading signals
Signals are information about possible trades, shared for your own decisions. You decide whether to trade and you are responsible for your trades and results. Past results do not guarantee future results.

## Changes
We may update these terms. The version on this page applies to orders placed after the change.
MD],
            ['privacy', 'Privacy policy', 2, <<<MD
We respect your privacy and handle personal data in line with Ethiopia's **Personal Data Protection Proclamation No. 1321/2024**.

## What we collect
- **When you order:** name, phone, optional email, city and delivery address, order details.
- **When you create an account:** name, phone, optional email and a password (stored only as a secure hash).
- **When you pay:** the receipt you upload and its transaction reference. We never ask for your PIN or bank password.
- **Membership and referrals:** your membership dates, the referral code you joined with, your earnings and cash-out details (account numbers are stored encrypted).
- **Telegram (signals):** your Telegram user ID, only so we can let you into the private channel and remove you when your access ends.

## What we do not collect
No tracking or advertising cookies, no analytics profiles, and no IP address for visitors who are not signed in. We use only two essential cookies: one for your cart and one that remembers a referral code for {$s('referral.cookie_days')} days. Signed-in customers also get a session cookie.

## Why we use your data
To deliver your orders, take payments, send order and renewal messages (SMS, email, Telegram), run the membership and referral program, prevent fraud, and meet our legal and tax duties.

## Who we share it with
Only with the services needed to serve you: couriers (name, phone and address for delivery), and SMS, email and Telegram providers for messages. We do not sell your data.

## Your team and your privacy
Other members only ever see your first name and last initial, the date you joined and whether you are active — and only if you joined with their link. Never your phone, email, address or purchases.

## How long we keep it
Order, payment and accounting records are kept as long as the law requires. Other data is kept while your account is active or until you ask us to delete it, unless we must keep it.

## Your rights
You can ask to see, correct or delete your personal data, to object to its use, and to receive a copy. Contact us at {$email} or {$phone}. You can also contact the Ethiopian Communications Authority.

## Security
Admin access uses two-factor authentication, sensitive data is encrypted, connections use HTTPS, and backups are encrypted.
MD],
            ['refund-shipping', 'Refund & shipping policy', 3, <<<MD
## Shipping
- We deliver all over Ethiopia through partner couriers such as EMS and HudHud.
- The shipping price depends on your city or zone and the weight of the parcel, and is shown before you pay.
- **Members ship free** on orders of {$m('shipping.member_free_over')} or more (goods after discounts).
- When your order ships we send the courier name and tracking number by SMS, email or Telegram.

## Returns and refunds
- If an item arrives damaged or is not what you ordered, tell us within **7 days** of delivery with photos. We will replace it or refund it.
- You can cancel an order for a full refund before it ships. Made-to-order items can be cancelled before we start making them.
- Refunds go back the way you paid: by transfer, or to your wallet if you paid from it.
- Referral earnings from a refunded order are reversed automatically.

## Membership and signals
- A membership or signals month can be refunded within **{$s('referral.pending_days')} days** of payment if you have not used its benefits. After a refund, the month's benefits end.

Questions? Call {$phone} or write to {$email}.
MD],
            ['membership-terms', 'Membership terms', 4, <<<MD
## Price and period
Membership costs **{$m('membership.price')} per month**. It is monthly only; there is no yearly plan.

## Benefits (while your membership is active)
- {$p('pricing.member_discount_bps')} off products (the member price is shown on every product)
- Free shipping on orders of {$m('shipping.member_free_over')} or more
- {$s('catalog.early_access_hours')}-hour early access to new products, and the members-only product of the month
- Trading signals included (normally {$m('signals.guest_price')} per month)
- Referral earnings (see the referral program rules)
- A {$m('loyalty.reward')} wallet reward after every {$s('loyalty.every_months')} months of continuous membership

## Renewal
Membership does not renew by itself. We remind you 3 days before, on the day and after it ends. If you renew before access ends, the new month is added on top and your membership stays continuous.

## Grace period and expiry
After your month ends you keep your benefits for **{$s('membership.grace_days')} more days**. After that, discounts, signals access and referral earnings stop until you renew. Your wallet balance stays yours. Earnings that would have been paid to you while you were not active are not paid later.

## Signals channel
Your personal Telegram link works for your Telegram account only. When your membership ends you are removed from the channel automatically. You can join again after renewing.
MD],
            ['referral-rules', 'Referral program rules', 5, <<<MD
## Who can earn
Only **active paying members** earn. Anyone can be referred — members, account holders and guests without an account.

## Your link and code
Every member gets a personal link and a short code. You can change your code once. A link someone opens is remembered for {$s('referral.cookie_days')} days; if they open several links, the latest one counts. Once someone creates an account, their referrer is fixed.

## What you earn
Earnings are a percentage of the amount actually paid (after discounts, without shipping):

| Purchase | Level 1 | Level 2 | Level 3 |
|---|---|---|---|
| Membership month | {$p('referral.rate.membership.l1')} | {$p('referral.rate.membership.l2')} | {$p('referral.rate.membership.l3')} |
| Shop products | {$p('referral.rate.products.l1')} | {$p('referral.rate.products.l2')} | {$p('referral.rate.products.l3')} |
| Signals (guests) | {$p('referral.rate.signals.l1')} | — | — |

Level 1 is the people you invite directly (no limit). Level 2 is the people they invite, and level 3 the next step. If the member at a level is not active when a purchase is paid, that level's earning is not paid (it is not passed to anyone else).

## When you can use it
Earnings are **pending for {$s('referral.pending_days')} days** (the refund window), then **available**. If a purchase is refunded, the earning is reversed.

## Using and cashing out
Use your available balance in the shop or for your membership, or cash out to Telebirr or a bank account from **{$m('wallet.cashout_min')}**, any time. The account name must match your name. We pay within {$s('wallet.payout_days')} days.

## Fair play
Referring yourself (same person, phone, email, device or payment account), fake accounts or spam are not allowed. Suspicious activity is reviewed; earnings and cash-outs can be held during a review and cancelled if the rules were broken.

## Privacy
You see first names and last initials of your direct invites, when they joined and whether they are active, plus counts for levels 2 and 3 and your earnings per level. You never see phones, emails, addresses, purchases or other members' wallets.
MD],
        ];

        foreach ($pages as [$slug, $title, $position, $body]) {
            Page::query()->firstOrCreate(['slug' => $slug], ['title' => $title, 'body' => PageTextUpdates::apply($body), 'position' => $position, 'in_footer' => true]);
        }
    }
}

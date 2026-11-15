<?php

/*
| Feature switches (Super Admin → Settings → Features).
| key => [label, group, default, parent]. A feature is effectively ON only
| when it and all its parents are on. Turning one off hides it in pages,
| API and admin; no data is deleted.
*/

return [
    'shop' => ['Shop', 'Shop', true, null],
    'shop.guest_checkout' => ['Guest checkout', 'Shop', true, 'shop'],
    'shop.coupons' => ['Coupons', 'Shop', true, 'shop'],
    'shop.free_shipping' => ['Free shipping for members', 'Shop', true, 'membership'],
    'shop.members_only_product' => ['Members-only product of the month', 'Shop', true, 'membership'],
    'shop.early_access' => ['Members early access', 'Shop', true, 'membership'],

    'membership' => ['Membership', 'Membership', true, null],
    'membership.member_discount' => ['Member discount on products', 'Membership', true, 'membership'],
    'loyalty_reward' => ['Loyalty reward', 'Membership', true, 'wallet'],

    'signals' => ['Trading signals', 'Signals', true, null],
    'signals.performance_page' => ['Public performance page', 'Signals', true, 'signals'],
    'telegram_bot' => ['Telegram bot', 'Signals', true, null],

    'referral' => ['Referral program', 'Referral', true, 'membership'],
    'referral.level1' => ['Referral level 1', 'Referral', true, 'referral'],
    'referral.level2' => ['Referral level 2', 'Referral', true, 'referral'],
    'referral.level3' => ['Referral level 3', 'Referral', true, 'referral'],
    'referral.commission.membership' => ['Commissions on membership', 'Referral', true, 'referral'],
    'referral.commission.signals' => ['Commissions on signals', 'Referral', true, 'referral'],
    'referral.commission.products' => ['Commissions on products', 'Referral', true, 'referral'],
    'badges' => ['Badges (team, loyalty, earnings, shopper)', 'Referral', true, null],
    'badges.messages' => ['Badge messages (SMS / email / Telegram when a badge unlocks; in-app always)', 'Referral', false, 'badges'],
    'top_referrers' => ['Top referrers list', 'Referral', true, 'referral'],

    'wallet' => ['Wallet', 'Wallet', true, null],
    'wallet.spend' => ['Pay with wallet in the shop', 'Wallet', true, 'wallet'],
    'wallet.cashout' => ['Cash out', 'Wallet', true, 'wallet'],

    'payments.chapa' => ['Chapa payments', 'Payments', true, null],
    'payments.manual' => ['Bank / Telebirr transfer with proof', 'Payments', true, null],

    'notifications.sms' => ['SMS notifications', 'Notifications', true, null],
    'notifications.email' => ['Email notifications', 'Notifications', true, null],
    'notifications.telegram' => ['Telegram notifications', 'Notifications', true, 'telegram_bot'],
];

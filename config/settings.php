<?php

/*
| Editable settings (Super Admin → Settings).
| key => [type, default, label, group]
| Types: money (integer santim), bps (basis points, 1500 = 15%),
|        ratio (×100, 250 = 2.5×), int, bool, string, text.
*/

return [
    // Membership
    'membership.price' => ['money', 500_000, 'Membership price per month', 'Membership'],
    'membership.grace_days' => ['int', 3, 'Grace period (days)', 'Membership'],
    'membership.reminder_days_before' => ['int', 3, 'Renewal reminder (days before)', 'Membership'],
    'membership.reminder_days_after' => ['int', 1, 'Renewal reminder (days after expiry)', 'Membership'],

    // Signals
    'signals.guest_price' => ['money', 400_000, 'Signals price per month (guests)', 'Signals'],
    'signals.grace_days' => ['int', 3, 'Signals grace period (days)', 'Signals'],
    'signals.risk_notice' => ['text', 'Trading involves risk. Past results do not guarantee future results.', 'Risk notice (signals pages)', 'Signals'],

    // Pricing & catalog
    'pricing.member_discount_bps' => ['bps', 1_500, 'Member discount on products', 'Pricing'],
    'shipping.member_free_over' => ['money', 300_000, 'Free shipping for members over', 'Pricing'],
    'catalog.early_access_hours' => ['int', 48, 'Member early access (hours)', 'Pricing'],
    'catalog.min_price_cost_ratio' => ['ratio', 250, 'Warn when price is below cost ×', 'Pricing'],
    'payments.fee_bps' => ['bps', 350, 'Payment fee estimate (for profit checks)', 'Pricing'],
    'catalog.low_stock' => ['int', 3, 'Low stock alert at', 'Pricing'],
    'orders.unpaid_cancel_hours' => ['int', 48, 'Cancel unpaid orders after (hours)', 'Pricing'],

    // Referral
    'referral.rate.membership.l1' => ['bps', 1_000, 'Membership commission L1', 'Referral'],
    'referral.rate.membership.l2' => ['bps', 500, 'Membership commission L2', 'Referral'],
    'referral.rate.membership.l3' => ['bps', 200, 'Membership commission L3', 'Referral'],
    'referral.rate.signals.l1' => ['bps', 1_000, 'Signals (guest) commission L1', 'Referral'],
    'referral.rate.products.l1' => ['bps', 600, 'Products commission L1', 'Referral'],
    'referral.rate.products.l2' => ['bps', 300, 'Products commission L2', 'Referral'],
    'referral.rate.products.l3' => ['bps', 100, 'Products commission L3', 'Referral'],
    'referral.cookie_days' => ['int', 30, 'Referral link memory (days)', 'Referral'],
    'referral.pending_days' => ['int', 14, 'Earnings pending period (days)', 'Referral'],
    'referral.daily_signup_limit' => ['int', 20, 'Max referred sign-ups per member per day', 'Referral'],

    // Wallet
    'wallet.cashout_min' => ['money', 100_000, 'Minimum cash out', 'Wallet'],
    'wallet.payout_days' => ['int', 3, 'Pay cash-outs within (days)', 'Wallet'],

    // Engagement
    'loyalty.reward' => ['money', 30_000, 'Loyalty reward', 'Engagement'],
    'loyalty.every_months' => ['int', 3, 'Loyalty reward every (months)', 'Engagement'],
    'badges.bronze' => ['int', 3, 'Bronze badge (active level-1 members)', 'Engagement'],
    'badges.silver' => ['int', 10, 'Silver badge (active level-1 members)', 'Engagement'],
    'badges.gold' => ['int', 25, 'Gold badge (active level-1 members)', 'Engagement'],

    // Site
    'site.name' => ['string', 'Kulmis Market', 'Shop name', 'Site'],
    'site.tagline' => ['string', 'Handcrafted in Ethiopia', 'Tagline', 'Site'],
    'site.support_phone' => ['string', '+251 900 000 000', 'Support phone', 'Site'],
    'site.support_email' => ['string', 'hello@kulmis.market', 'Support email', 'Site'],
    'site.telegram_support' => ['string', '', 'Telegram support username', 'Site'],

    // Manual payment instructions
    'payments.bank_details' => ['text', "Commercial Bank of Ethiopia\nAccount name: Kulmis Market\nAccount number: 1000000000000", 'Bank transfer details', 'Payments'],
    'payments.telebirr_details' => ['text', 'Telebirr: +251 900 000 000 (Kulmis Market)', 'Telebirr transfer details', 'Payments'],
];

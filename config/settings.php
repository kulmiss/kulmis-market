<?php

/*
| Editable settings (Super Admin → Settings).
| key => [type, default, label, group]
| Types: money (integer santim), bps (basis points, 1500 = 15%),
|        ratio (×100, 250 = 2.5×), int, bool, string, text,
|        choice (5th item = allowed values), currency_code (3 capital letters),
|        color (#RRGGBB).
*/

use App\Support\Currency;

return [
    // General: currency. Changing it does NOT convert prices or balances.
    'currency.code' => ['currency_code', 'ETB', 'Currency code (ISO, e.g. ETB, USD)', 'General'],
    'currency.symbol' => ['string', 'ETB', 'Currency symbol (e.g. ETB, Br, $)', 'General'],
    'currency.position' => ['choice', 'after_space', 'Symbol position', 'General', Currency::POSITIONS],
    'currency.decimals' => ['choice', 'auto', 'Decimals (auto = only when not whole)', 'General', Currency::DECIMALS],
    'currency.thousands' => ['choice', ',', 'Thousands separator', 'General', Currency::THOUSANDS],
    'currency.decimal_point' => ['choice', '.', 'Decimal separator', 'General', Currency::DECIMAL_POINTS],

    // Appearance: the member area (the admin keeps its own look).
    'appearance.member_accent' => ['color', '#1f8a4c', 'Member area accent colour (green by default; light and dark mode follow it)', 'Appearance'],

    // Demo data (Super Admin → Demo data). Off hides every demo record everywhere.
    'demo.show' => ['bool', true, 'Show demo data (storefront, member areas, admin lists and dashboards)', 'Demo data'],

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
    'referral.same_network_signups' => ['int', 10, 'Sign-ups for one referrer from the same public network in 24 h that are shown for review (no hold)', 'Referral'],

    // Wallet
    'wallet.cashout_min' => ['money', 100_000, 'Minimum cash out', 'Wallet'],
    'wallet.payout_days' => ['int', 3, 'Pay cash-outs within (days)', 'Wallet'],
    'wallet.payout_account_hold_hours' => ['int', 48, 'Wait after a cash-out account is added or changed (hours)', 'Wallet'],
    'wallet.cashout_daily_max' => ['money', 5_000_000, 'Most a member can cash out in 24 hours', 'Wallet'],
    'wallet.cashout_daily_count' => ['int', 2, 'Most cash-out requests per member in 24 hours', 'Wallet'],
    'wallet.new_account_review_days' => ['int', 30, 'First cash-out of an account younger than this is held for review (days)', 'Wallet'],

    // Engagement
    'loyalty.reward' => ['money', 30_000, 'Loyalty reward', 'Engagement'],
    'loyalty.every_months' => ['int', 3, 'Loyalty reward every (months)', 'Engagement'],

    // Badge bonus (amounts per badge: Badges page). Safety limits keep the shop in profit.
    'bonus.budget' => ['bps', 500, 'Most of a month\'s membership money that goes to badge bonuses', 'Badge bonus'],
    'bonus.team_limit' => ['bps', 200, 'Most per member: share of the membership money their own invited people paid that month', 'Badge bonus'],
    'bonus.pending_days' => ['int', 14, 'Days a badge bonus is on the way before it can be used', 'Badge bonus'],

    // Site
    'site.name' => ['string', 'Kulmis Market', 'Shop name', 'Site'],
    'site.tagline' => ['string', 'Handcrafted in Ethiopia', 'Tagline', 'Site'],
    'site.support_phone' => ['string', '+251 900 000 000', 'Support phone', 'Site'],
    'site.support_email' => ['string', 'hello@kulmis.market', 'Support email', 'Site'],
    'site.telegram_support' => ['string', '', 'Telegram support username', 'Site'],

    // Customer messages (SMS / email / Telegram). Placeholders:
    // {name} {number} {total} {courier} {tracking} {tracking_link} {track_url} {making_days} {reason} {site}
    'notify.order_paid' => ['text', 'Hi {name}, we received your payment for order {number} ({total}). Track it here: {track_url}', 'Message: order paid', 'Messages'],
    'notify.order_making' => ['text', 'Hi {name}, your order {number} is being handmade now. It should be ready in about {making_days} days.', 'Message: being made', 'Messages'],
    'notify.order_shipped' => ['text', 'Hi {name}, order {number} is on its way with {courier}. Tracking number: {tracking} {tracking_link}', 'Message: shipped', 'Messages'],
    'notify.order_delivered' => ['text', 'Hi {name}, order {number} has been delivered. Thank you for choosing handmade at {site}!', 'Message: delivered', 'Messages'],
    'notify.order_cancelled' => ['text', 'Hi {name}, order {number} was cancelled. {reason}', 'Message: cancelled', 'Messages'],

    // Membership messages. Placeholders: {name} {date} {grace_date} {price} {renew_url} {site} {amount}
    'notify.membership_activated' => ['text', 'Welcome to {site} membership, {name}! Your benefits are active until {date}.', 'Message: membership active', 'Messages'],
    'notify.membership_before' => ['text', 'Hi {name}, your membership renews on {date}. Renew now to keep your discounts, signals and earnings: {renew_url}', 'Message: renewal reminder (before)', 'Messages'],
    'notify.membership_today' => ['text', 'Hi {name}, your membership ends today. Renew ({price}) to keep your benefits: {renew_url}', 'Message: renewal reminder (on the day)', 'Messages'],
    'notify.membership_after' => ['text', 'Hi {name}, your membership has ended. You still have benefits until {grace_date}. Renew here: {renew_url}', 'Message: renewal reminder (after)', 'Messages'],
    'notify.loyalty_reward' => ['text', 'Thank you for staying with us, {name}! We added {amount} to your wallet.', 'Message: loyalty reward', 'Messages'],

    'notify.signals_access' => ['text', 'Hi {name}, your trading signals access is ready. Open this personal link in Telegram to join (do not share it): {link}', 'Message: signals access link', 'Messages'],

    'notify.badge_bonus' => ['text', 'Well done {name}! Your {badge} badge bonus for {month} is {amount}. It is on the way and ready to use on {ready}.{adjusted}', 'Message: monthly badge bonus', 'Messages'],
    'notify.badge_unlocked' => ['text', 'Congratulations {name}! You unlocked the {badge} badge on {site}. See your badges: {url}', 'Message: badge unlocked (only when "Badge messages" is on)', 'Messages'],

    'notify.payout_paid' => ['text', 'Hi {name}, we sent your cash-out of {amount}. Reference: {reference}', 'Message: cash-out paid', 'Messages'],
    'notify.payout_rejected' => ['text', 'Hi {name}, your cash-out of {amount} was not approved and is back in your wallet. {reason}', 'Message: cash-out rejected', 'Messages'],
    'notify.security_new_sign_in' => ['text', 'Hi {name}, someone signed in to your {site} account on {device} ({when}). If this was you, you can ignore this. If not, change your password and sign out all devices: {secure_url}', 'Security: new sign-in', 'Messages'],
    'notify.security_password_changed' => ['text', 'Hi {name}, your {site} password was changed ({when}) and other devices were signed out. Not you? Reset your password now and contact us: {secure_url}', 'Security: password changed', 'Messages'],
    'notify.security_email_change_requested' => ['text', 'Hi {name}, someone asked to change the email on your {site} account to {new_email}. It only changes after the link sent to that address is opened. Not you? Change your password: {secure_url}', 'Security: email change requested (to old address)', 'Messages'],
    'notify.security_email_changed' => ['text', 'Hi {name}, the email on your {site} account is now {new_email} ({when}). Not you? Contact us right away.', 'Security: email changed', 'Messages'],
    'notify.security_payout_account_changed' => ['text', 'Hi {name}, your cash-out account was changed to {account} ({when}). For your safety, cash-outs to it are possible after {usable_at}. Not you? Change your password and contact us: {secure_url}', 'Security: payout account changed', 'Messages'],
    'notify.security_two_factor_on' => ['text', 'Hi {name}, two-step sign-in is now ON for your {site} account ({when}).', 'Security: 2FA on', 'Messages'],
    'notify.security_two_factor_off' => ['text', 'Hi {name}, two-step sign-in was turned OFF for your {site} account ({when}). Not you? Change your password now: {secure_url}', 'Security: 2FA off', 'Messages'],
    'notify.security_locked' => ['text', 'Hi {name}, there were too many wrong passwords on your {site} account, so sign-in is paused for a few minutes. Not you? Nothing to do — but a stronger password helps: {secure_url}', 'Security: sign-in paused', 'Messages'],
    'notify.security_signed_out_everywhere' => ['text', 'Hi {name}, your {site} account was signed out on all devices ({when}).', 'Security: signed out everywhere', 'Messages'],

    // Manual payment instructions
    'payments.bank_details' => ['text', "Commercial Bank of Ethiopia\nAccount name: Kulmis Market\nAccount number: 1000000000000", 'Bank transfer details', 'Payments'],
    'payments.telebirr_details' => ['text', 'Telebirr: +251 900 000 000 (Kulmis Market)', 'Telebirr transfer details', 'Payments'],
];

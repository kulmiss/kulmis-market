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
    'currency.code' => ['currency_code', 'ETB', 'Currency code (3 letters, e.g. ETB, USD)', 'General'],
    'currency.symbol' => ['string', 'ETB', 'Currency symbol (e.g. ETB, Br, $)', 'General'],
    'currency.position' => ['choice', 'after_space', 'Symbol position', 'General', Currency::POSITIONS],
    'currency.decimals' => ['choice', 'auto', 'Show cents (auto = only when needed)', 'General', Currency::DECIMALS],
    'currency.thousands' => ['choice', ',', 'Thousands separator', 'General', Currency::THOUSANDS],
    'currency.decimal_point' => ['choice', '.', 'Decimal separator', 'General', Currency::DECIMAL_POINTS],

    // Appearance: the member area (the admin keeps its own look).
    'appearance.member_accent' => ['color', '#1f8a4c', 'Member area accent colour (green by default; light and dark mode follow it)', 'Appearance'],

    // Demo data (Super Admin → Demo data). Off hides every demo record everywhere.
    'demo.show' => ['bool', true, 'Show demo data (storefront, member areas, admin lists and dashboards)', 'Demo data'],

    // Membership
    'membership.price' => ['money', 500_000, 'Membership price per month', 'Membership'],
    'membership.grace_days' => ['int', 3, 'Extra days after the end before membership stops (grace days)', 'Membership'],
    'membership.reminder_days_before' => ['int', 3, 'Remind to renew: days before the end', 'Membership'],
    'membership.reminder_days_after' => ['int', 1, 'Remind again: days after the end', 'Membership'],

    // Signals
    'signals.guest_price' => ['money', 400_000, 'Signals price per month (guests)', 'Signals'],
    'signals.grace_days' => ['int', 3, 'Extra days after signals end (grace days)', 'Signals'],
    'signals.risk_notice' => ['text', 'Trading involves risk. Past results do not guarantee future results.', 'Risk notice (signals pages)', 'Signals'],

    // Pricing & catalog
    'pricing.member_discount_bps' => ['bps', 1_500, 'Member price cut on products', 'Pricing'],
    'giftcards.amounts' => ['string', '500,1000,2000,5000', 'Gift card amounts people can buy (ETB, comma separated)', 'Gift cards'],
    'giftcards.valid_days' => ['int', 365, 'Gift cards: days they can be used (0 = no end date)', 'Gift cards'],
    'extras.gift_wrap_price' => ['money', 15_000, 'Checkout extra: gift wrapping price', 'Checkout extras'],
    'extras.express_price' => ['money', 30_000, 'Checkout extra: express making and delivery price', 'Checkout extras'],
    'extras.insurance_bps' => ['bps', 150, 'Checkout extra: shipping insurance (share of the goods)', 'Checkout extras'],
    'extras.insurance_min' => ['money', 2_000, 'Checkout extra: shipping insurance at least', 'Checkout extras'],
    'shipping.member_free_over' => ['money', 300_000, 'Free delivery for members when the order is at least', 'Pricing'],
    'catalog.early_access_hours' => ['int', 48, 'Members can buy new products this many hours early', 'Pricing'],
    'catalog.min_price_cost_ratio' => ['ratio', 250, 'Warn me when the price is less than cost ×', 'Pricing'],
    'payments.fee_bps' => ['bps', 350, 'Payment fee you pay (used to check profit)', 'Pricing'],
    'catalog.low_stock' => ['int', 3, 'Warn me when stock is down to', 'Pricing'],
    'orders.unpaid_cancel_hours' => ['int', 48, 'Cancel orders not paid after (hours)', 'Pricing'],

    // Referral
    'referral.rate.membership.l1' => ['bps', 1_000, 'Team earnings on memberships: level 1 (people they invited)', 'Referral'],
    'referral.rate.membership.l2' => ['bps', 500, 'Team earnings on memberships: level 2', 'Referral'],
    'referral.rate.membership.l3' => ['bps', 200, 'Team earnings on memberships: level 3', 'Referral'],
    'referral.rate.signals.l1' => ['bps', 1_000, 'Team earnings on signals bought by guests: level 1', 'Referral'],
    'referral.rate.products.l1' => ['bps', 600, 'Team earnings on shop orders: level 1', 'Referral'],
    'referral.rate.products.l2' => ['bps', 300, 'Team earnings on shop orders: level 2', 'Referral'],
    'referral.rate.products.l3' => ['bps', 100, 'Team earnings on shop orders: level 3', 'Referral'],
    'referral.rate.addons.l1' => ['bps', 600, 'Team earnings on paid add-ons, level 1', 'Referral'],
    'referral.rate.addons.l2' => ['bps', 300, 'Team earnings on paid add-ons, level 2', 'Referral'],
    'referral.rate.addons.l3' => ['bps', 100, 'Team earnings on paid add-ons, level 3', 'Referral'],
    'custom.deposit_bps' => ['bps', 5_000, 'Custom orders: deposit to start (share of the quote)', 'Shop'],
    'custom.quote_days' => ['int', 7, 'Custom orders: days a quote waits for the deposit', 'Shop'],
    'winback.days' => ['int', 60, 'Win-back offer: send after this many days without an order', 'Engagement'],
    'winback.percent_bps' => ['bps', 1_000, 'Win-back offer: discount (one use, 14 days)', 'Engagement'],
    'notify.winback' => ['text', 'Hi {name}, we miss you at {site}! Here is {percent} off your next order with code {code} (one use, until {until}): {url}', 'Message: win-back offer', 'Messages'],
    'digital.link_days' => ['int', 7, 'Digital products: days a download link works', 'Shop'],
    'digital.max_downloads' => ['int', 5, 'Digital products: downloads per link', 'Shop'],
    'referral.cookie_days' => ['int', 30, 'A referral link is remembered for (days)', 'Referral'],
    'referral.pending_days' => ['int', 14, 'Days team earnings are on the way before they are ready to use', 'Referral'],
    'referral.daily_signup_limit' => ['int', 20, 'Most sign-ups one member can bring in a day', 'Referral'],
    'referral.same_network_signups' => ['int', 10, 'Show me for a check when one member brings this many sign-ups from the same internet connection in 24 hours (money is not held)', 'Referral'],

    // Wallet
    'wallet.cashout_min' => ['money', 100_000, 'Smallest cash-out', 'Wallet'],
    'wallet.payout_days' => ['int', 3, 'We pay cash-outs within (days)', 'Wallet'],
    'wallet.payout_account_hold_hours' => ['int', 48, 'Wait before a new or changed cash-out account can be used (hours)', 'Wallet'],
    'wallet.cashout_fee_fixed' => ['money', 0, 'Cash-out fee: fixed amount (0 = none)', 'Wallet'],
    'wallet.cashout_fee_bps' => ['bps', 0, 'Cash-out fee: share of the amount (0 = none)', 'Wallet'],
    'wallet.cashout_daily_max' => ['money', 5_000_000, 'Most a member can cash out in 24 hours', 'Wallet'],
    'wallet.cashout_daily_count' => ['int', 2, 'Most cash-out requests per member in 24 hours', 'Wallet'],
    'wallet.new_account_review_days' => ['int', 30, 'Check the first cash-out of accounts newer than (days)', 'Wallet'],

    // Engagement
    'loyalty.reward' => ['money', 30_000, 'Loyalty reward (money for members who stay)', 'Engagement'],
    'loyalty.every_months' => ['int', 3, 'Give the loyalty reward every (months in a row)', 'Engagement'],

    // Badge bonus (amounts per badge: Badges page). Safety limits keep the shop in profit.
    'bonus.budget' => ['bps', 500, 'Most of a month\'s membership money that goes to badge bonuses', 'Badge bonus'],
    'bonus.team_limit' => ['bps', 200, 'Most per member: share of the membership money their own invited people paid that month', 'Badge bonus'],
    'bonus.first_payment' => ['money', 25_000, 'First-payment bonus: paid to the inviter when someone they brought pays for the first time', 'Badge bonus'],
    'contest.budget' => ['bps', 200, 'Contests: most of the membership money paid during the contest that goes to prizes (prizes are cut to fit)', 'Contests and challenges'],
    'challenge.budget' => ['bps', 200, 'Team challenges: most of a month\'s membership money that goes to challenge rewards', 'Contests and challenges'],
    'reward.comeback' => ['money', 0, 'Welcome-back reward for a member who comes back (0 = off)', 'Engagement'],
    'reward.comeback_days' => ['int', 30, 'Welcome-back reward: away for at least (days)', 'Engagement'],
    'bonus.pending_days' => ['int', 14, 'Days a badge bonus is on the way before it can be used', 'Badge bonus'],

    // Site
    'site.name' => ['string', 'Kulmis Market', 'Shop name', 'Site'],
    'site.tagline' => ['string', 'Handcrafted in Ethiopia', 'Short line under the shop name', 'Site'],
    'site.support_phone' => ['string', '+251 900 000 000', 'Support phone', 'Site'],
    'site.support_email' => ['string', 'hello@kulmis.market', 'Support email', 'Site'],
    'site.telegram_support' => ['string', '', 'Telegram support username', 'Site'],
    'site.whatsapp' => ['string', '', 'WhatsApp number for the help centre (empty = support phone)', 'Site'],

    // Customer messages (SMS / email / Telegram). Placeholders:
    // {name} {number} {total} {courier} {tracking} {tracking_link} {track_url} {making_days} {reason} {site}
    'notify.order_paid' => ['text', 'Hi {name}, we received your payment for order {number} ({total}). Track it here: {track_url}', 'Message: order paid', 'Messages'],
    'notify.order_making' => ['text', 'Hi {name}, your order {number} is being handmade now. It should be ready in about {making_days} days.', 'Message: being made', 'Messages'],
    'notify.order_shipped' => ['text', 'Hi {name}, order {number} is on its way with {courier}. Tracking number: {tracking} {tracking_link}', 'Message: shipped', 'Messages'],
    'notify.order_delivered' => ['text', 'Hi {name}, order {number} has been delivered. Thank you for choosing handmade at {site}!', 'Message: delivered', 'Messages'],
    'notify.order_cancelled' => ['text', 'Hi {name}, order {number} was cancelled. {reason}', 'Message: cancelled', 'Messages'],

    // Membership messages. Placeholders: {name} {date} {grace_date} {price} {renew_url} {site} {amount}
    'notify.membership_activated' => ['text', 'Welcome to {site} membership, {name}! Your benefits are active until {date}.', 'Message: membership active', 'Messages'],
    'notify.membership_before' => ['text', 'Hi {name}, your membership ends on {date}. Pay now to keep your {benefits}: {renew_url}', 'Message: renewal reminder (before)', 'Messages'],
    'notify.membership_today' => ['text', 'Hi {name}, your membership ends today. Renew ({price}) to keep your {benefits}: {renew_url}', 'Message: renewal reminder (on the day)', 'Messages'],
    'notify.membership_after' => ['text', 'Hi {name}, your membership has ended. Your {benefits} stay until {grace_date}. Pay here to keep them: {renew_url}', 'Message: renewal reminder (after)', 'Messages'],
    'notify.loyalty_reward' => ['text', 'Thank you for staying with us, {name}! We added {amount} to your wallet.', 'Message: loyalty reward', 'Messages'],

    'notify.signals_access' => ['text', 'Hi {name}, your trading signals access is ready. Open this personal link in Telegram to join (do not share it): {link}', 'Message: signals access link', 'Messages'],

    'notify.back_in_stock' => ['text', 'Good news! {product} is back in stock at {site}. Get it here before it sells out: {url}', 'Message: back in stock', 'Messages'],
    'notify.cart_reminder' => ['text', 'Hi {name}, you left {items} in your cart at {site}. It is still there for you: {url} (To stop these: {stop})', 'Message: cart reminder', 'Messages'],
    'cart_reminder.hours' => ['int', 24, 'Cart reminder: hours after the last change', 'Messages'],
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

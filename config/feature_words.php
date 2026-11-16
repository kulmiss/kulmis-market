<?php

/*
 * Words that belong to one feature. When the feature is switched off:
 * - customers and members must not see any of them (tests crawl every page,
 *   API answer, message and the sitemap for them), and
 * - the admin warns about editable texts (FAQ, pages, announcements, share
 *   kit, message templates) that still use them.
 * Lower case; matched as whole words (a word may be a few words long).
 */
return [
    'signals' => ['signal', 'signals', 'trading', 'telegram channel', 'win rate', 'xau/usd'],
    'signals.performance_page' => ['signal results', 'performance page'],
    'membership' => ['membership', 'join & save', 'join &amp; save', 'member price', 'member prices', 'members pay', 'become a member'],
    'membership.member_discount' => ['member price', 'member prices', 'members pay'],
    'membership.gifts' => ['gift membership', 'gift a month', 'gift month', 'give a month'],
    'loyalty_reward' => ['loyalty reward'],
    'referral' => ['referral', 'referrals', 'invite', 'your team', 'team earnings', 'commission', 'commissions', 'earn from'],
    'referral.share_kit' => ['share kit'],
    'referral.qr_codes' => ['qr code'],
    'referral.contests' => ['contest', 'contests'],
    'referral.challenges' => ['challenge', 'challenges'],
    'top_referrers' => ['top referrer', 'top referrers'],
    'badges' => ['badge', 'badges'],
    'badges.bonus' => ['badge bonus', 'monthly bonus'],
    'wallet' => ['wallet'],
    'wallet.cashout' => ['cash out', 'cash-out', 'cash-outs'],
    'shop' => ['add to cart', 'checkout', 'my orders', 'track order', 'shop the collection'],
    'shop.coupons' => ['coupon', 'coupons'],
    'shop.free_shipping' => ['free shipping', 'free delivery'],
    'shop.early_access' => ['early access'],
    'shop.wishlist' => ['wishlist'],
    'shop.reviews' => ['reviews', 'write a review'],
    'shop.flash_sales' => ['flash sale', 'flash sales'],
    'shop.bundles' => ['bundle', 'bundles', 'save with a set', 'add the set to cart'],
    'shop.digital' => ['digital', 'download links', 'my downloads', 'digital download', 'digital downloads'],
    'shop.addons' => ['add-on', 'add-ons', 'seats left'],
    'shop.preorders' => ['pre-order', 'pre-orders'],
    'shop.custom_orders' => ['custom order', 'custom orders'],
    'shop.gift_cards' => ['gift card', 'gift cards'],
    'shop.order_tracking' => ['track order', 'track your order'],
    'site.help_centre' => ['help centre', 'help and contact'],
    'account.goals' => ['monthly goal'],
    'account.app' => ['home screen', 'install the app'],
    'accounts.signup' => ['create account', 'create an account', 'sign up'],
    'accounts.two_factor' => ['two-step'],
];

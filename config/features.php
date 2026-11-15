<?php

/*
| Feature switches (Super Admin → Feature switches).
| key => [label, section, default, parent, easy description].
| A feature is ON only when it and all its parents are on. Turning one off
| hides it in pages, the app (API), the admin and background jobs. No data
| is deleted: turn it back on and everything is still there.
|
| Each badge also gets a switch (key "badge.<key>", from the badges table).
*/

return [
    // Shop
    'shop' => ['Shop', 'Shop', true, null, 'The whole shop: products, cart, checkout and orders.'],
    'shop.guest_checkout' => ['Guest checkout', 'Shop', true, 'shop', 'People can order without making an account.'],
    'shop.coupons' => ['Coupons', 'Shop', true, 'shop', 'Customers can type a coupon code in the cart.'],
    'shop.free_shipping' => ['Free shipping for members', 'Shop', true, 'membership', 'Members pay no delivery above the amount set in Settings.'],
    'shop.members_only_product' => ['Members-only product of the month', 'Shop', true, 'membership', 'One product that only members can buy.'],
    'shop.early_access' => ['Members early access', 'Shop', true, 'membership', 'Members can buy new products a few hours before everyone else.'],
    'shop.wishlist' => ['Wishlist', 'Shop', true, 'shop', 'Signed-in customers can save products with a heart and find them later.'],
    'shop.stock_alerts' => ['"Tell me when it\'s back"', 'Shop', true, 'shop', 'People can leave their phone or email on a sold-out item and get one message when it is back.'],
    'shop.buy_again' => ['Buy again', 'Shop', true, 'shop', 'A "Buy again" button on past orders puts the same items in the cart (today\'s prices).'],
    'shop.saved_addresses' => ['Saved addresses', 'Shop', true, 'shop', 'Signed-in customers can save several delivery addresses and pick one at checkout.'],
    'shop.reviews' => ['Product reviews', 'Shop', true, 'shop', 'People who bought and received a product can rate it and add photos. You approve each one first.'],
    'shop.flash_sales' => ['Flash sales', 'Shop', true, 'shop', 'A lower price for a short time, with a countdown and (if you want) limited pieces. Members still get their member price on top.'],
    'shop.bundles' => ['Bundles', 'Shop', true, 'shop', 'Sets of products at one price. When every piece of a set is in the cart, the saving comes off by itself.'],
    'shop.upsells' => ['"Bought together" and "You may also like"', 'Shop', true, 'shop', 'Matching products on product pages, in the cart and at checkout, with a one-tap "Add" button.'],
    'shop.order_tracking' => ['Order tracking page', 'Shop', true, 'shop', 'The "Track order" page and its links in menus and messages.'],

    // Payments
    'payments.manual' => ['Bank / Telebirr transfer', 'Payments', true, null, 'Customers pay by transfer and send a photo of the receipt. Staff check it.'],
    'payments.pdf_receipts' => ['PDF receipts', 'Payments', true, null, 'Customers and staff can download a PDF receipt for a paid order.'],
    'wallet.spend' => ['Pay with wallet', 'Payments', true, 'wallet', 'Members can use money in their wallet to pay in the shop.'],

    // Membership
    'membership' => ['Membership', 'Membership', true, null, 'People can buy a monthly membership.'],
    'membership.member_discount' => ['Member discount on products', 'Membership', true, 'membership', 'Members pay a lower price in the shop.'],
    'loyalty_reward' => ['Loyalty reward', 'Membership', true, 'wallet', 'Members who stay long get money in their wallet (amount in Settings).'],

    // Referral
    'referral' => ['Referral program', 'Referral', true, 'membership', 'Members get a link and earn when people join with it.'],
    'referral.level1' => ['Level 1 earnings', 'Referral', true, 'referral', 'Earn from people you invited yourself.'],
    'referral.level2' => ['Level 2 earnings', 'Referral', true, 'referral', 'Earn from people your team invited.'],
    'referral.level3' => ['Level 3 earnings', 'Referral', true, 'referral', 'Earn from the next level after that.'],
    'referral.commission.membership' => ['Earnings on memberships', 'Referral', true, 'referral', 'Pay team earnings when someone buys a membership.'],
    'referral.commission.signals' => ['Earnings on signals', 'Referral', true, 'referral', 'Pay team earnings when someone buys signals.'],
    'referral.commission.products' => ['Earnings on products', 'Referral', true, 'referral', 'Pay team earnings when someone buys in the shop.'],
    'top_referrers' => ['Top referrers list', 'Referral', true, 'referral', 'The public list of the best inviters this month (first name only).'],
    'referral.share_buttons' => ['Share buttons', 'Referral', true, 'referral', 'Telegram, WhatsApp and "More" buttons to share the referral link.'],
    'referral.qr_codes' => ['QR codes', 'Referral', true, 'referral', 'A QR code of the referral link that friends can scan.'],

    // Badges
    'badges' => ['Badges', 'Badges', true, null, 'Members unlock badges for their team, loyalty, earnings and orders.'],
    'badges.bonus' => ['Badge bonuses', 'Badges', true, 'badges', 'Bronze to Diamond pay a bonus every month; other badges can pay once. Safety limits keep you in profit.'],
    'badges.messages' => ['Badge messages', 'Badges', false, 'badges', 'Send an SMS / email / Telegram when a badge unlocks. (The in-app note is always shown.)'],

    // Wallet
    'wallet' => ['Wallet', 'Wallet', true, null, 'Members see their money: on the way, ready to use, and cash-outs.'],
    'wallet.cashout' => ['Cash-out', 'Wallet', true, 'wallet', 'Members can ask to get their money sent to their bank or Telebirr.'],

    // Signals
    'signals' => ['Trading signals', 'Signals', true, null, 'The signals page, buying signals and signals for members.'],
    'signals.performance_page' => ['Public signal results', 'Signals', true, 'signals', 'Everyone can see the results of past signals.'],
    'telegram_bot' => ['Telegram bot', 'Signals', true, null, 'The bot that lets people into the signals group and sends messages.'],

    // Accounts
    'accounts.signup' => ['Sign-up', 'Accounts', true, null, 'New people can make an account. Off: only existing accounts can sign in.'],
    'accounts.two_factor' => ['Two-step sign-in for customers', 'Accounts', true, null, 'Customers can turn on a code from an app. Off: no new set-ups; people who already use it keep it.'],

    // Notification channels
    'notifications.sms' => ['SMS', 'Notification channels', true, null, 'Send customer messages by SMS.'],
    'notifications.email' => ['Email', 'Notification channels', true, null, 'Send customer messages by email.'],
    'notifications.telegram' => ['Telegram', 'Notification channels', true, 'telegram_bot', 'Send customer messages in Telegram.'],

    // Message types (text in Settings → Messages). Account-safety messages
    // and password-reset codes are always sent.
    'messages.order_paid' => ['Order paid', 'Messages', true, 'shop', 'Tell the customer we got the money for their order.'],
    'messages.order_making' => ['Order being made', 'Messages', true, 'shop', 'Tell the customer their order is being handmade.'],
    'messages.order_shipped' => ['Order sent', 'Messages', true, 'shop', 'Tell the customer their order is on its way.'],
    'messages.order_delivered' => ['Order delivered', 'Messages', true, 'shop', 'Tell the customer their order arrived.'],
    'messages.order_cancelled' => ['Order cancelled', 'Messages', true, 'shop', 'Tell the customer their order was cancelled.'],
    'messages.back_in_stock' => ['Back in stock', 'Messages', true, 'shop.stock_alerts', 'Tell people a sold-out item they asked about is back.'],
    'messages.membership_activated' => ['Membership started', 'Messages', true, 'membership', 'Welcome message when a membership starts.'],
    'messages.membership_before' => ['Renew soon', 'Messages', true, 'membership', 'Reminder before the membership ends.'],
    'messages.membership_today' => ['Ends today', 'Messages', true, 'membership', 'Reminder on the last day.'],
    'messages.membership_after' => ['Membership ended', 'Messages', true, 'membership', 'Message after the membership ended.'],
    'messages.loyalty_reward' => ['Loyalty reward', 'Messages', true, 'loyalty_reward', 'Tell the member we added their loyalty reward.'],
    'messages.signals_access' => ['Signals link', 'Messages', true, 'signals', 'Send the personal Telegram link. Off: they find it in their account only.'],
    'messages.payout_paid' => ['Cash-out sent', 'Messages', true, 'wallet.cashout', 'Tell the member we sent their money.'],
    'messages.badge_bonus' => ['Badge bonus', 'Messages', true, 'badges.bonus', 'Tell the member how much badge bonus they got this month.'],
    'messages.payout_rejected' => ['Cash-out not approved', 'Messages', true, 'wallet.cashout', 'Tell the member their cash-out was not approved.'],

    // Look and speed
    'appearance.theme_switch' => ['Light / dark switch', 'Look and speed', true, null, 'Customers can switch between light and dark. Off: always light.'],
    'site.instant_loading' => ['Instant page loading', 'Look and speed', true, null, 'Pages load before the click, so the shop feels instant.'],
    'site.offline_page' => ['Offline page', 'Look and speed', true, null, 'Saves pages on the phone, and shows a friendly page with no internet.'],
];

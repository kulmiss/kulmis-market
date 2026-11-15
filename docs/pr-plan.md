# PR plan and merge order

The PRs are stacked: each branch is built on the one before it. Every PR stays
a **draft** except the top one, so CI runs only on the top. Merge them in
order, 1 → 20. After merging one, retarget the next PR to `main`.

| # | Branch | Scope |
|---|--------|-------|
| 1 | `pr/01-scaffold` | Laravel 13, Docker, CI (Pint, PHPUnit on MySQL), security headers, `/api/v1`, scheduler-driven queue, docs |
| 2 | `pr/02-platform-core` | Feature switches (hide a feature everywhere without deleting data, plus an overview page), typed settings, roles and permissions, activity log, admin 2FA |
| 3 | `pr/03-money-ledger` | Integer-santim Money type, double-entry ledger, balances derived from postings, reasons required for manual entries, idempotency keys |
| 4 | `pr/04-catalog` | Products, variants, stock or made-to-order, images (WebP/AVIF), categories and tags, members-only and early-access flags, cost field, warning when price < 2.5× cost |
| 5 | `pr/05-pricing` | Guest and member prices, coupons, free shipping, profitability check |
| 6 | `pr/06-cart-checkout` | Cart, guest checkout, orders, tracking by phone + order number |
| 7 | `pr/07-shipping-notify` | Zones, weights, couriers, status flow, notifications by SMS, email and Telegram |
| 8 | `pr/08-payments` | Chapa (webhook signatures, idempotency), bank/Telebirr proof upload, PDF receipts, refunds |
| 9 | `pr/09-membership` | Monthly plan, reminders, grace period, expiry, loyalty reward |
| 10 | `pr/10-signals` | Guest signals plan, Telegram bot (invite links, removal), signal posting, results log, performance page, risk notice |
| 11 | `pr/11-referrals` | Codes and links, 30-day cookie, locked referrer, commissions on 3 levels, expired members skipped, 14-day pending period, refund reversal |
| 12 | `pr/12-wallet-cashout` | Wallet spending, cash-out flow, fraud queue, payout holds |
| 13 | `pr/13-engagement` | Badges, top referrers, member dashboard |
| 14 | `pr/14-api-v1` | Sanctum auth and full REST resources |
| 15 | `pr/15-admin-shell` | shadcn-admin app in `admin/`, auth, feature overview |
| 16 | `pr/16-admin-shop` | Products, stock, orders, shipping, members |
| 17 | `pr/17-admin-money` | Ledger, cash-outs, fraud queue, referral tree, signals, Telegram |
| 18 | `pr/18-admin-dashboards` | KPIs, MRR and churn, profit, queues |
| 19 | `pr/19-storefront` | Design, landing pages, light/dark, SEO and social images, static page cache, Lighthouse CI |
| 20 | `pr/20-texts-golive` | Default legal texts, hardening, encrypted backups, deploy guides |

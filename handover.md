# Handover — Kulmis Market

_Last updated: PR 23._

Kulmis Market is an online shop for handcrafted Ethiopian products, plus a
trading-signals service (private Telegram channel) and a paid monthly
membership with a 3-level referral program. It's priced in ETB, written in
English (ready for Amharic) and aimed at customers in Ethiopia.

## Stack
- **Backend:** Laravel 13 (PHP 8.3), MySQL/MariaDB, database queue + one
  cron line
- **Storefront:** server-rendered Blade + Tailwind 4 + about 10 KB of vanilla
  JS, with a static page cache served without PHP
- **Admin:** React (satnaing/shadcn-admin + shadcn/ui) at `/admin`
- **API:** REST `/api/v1`, ready for the Flutter app (Sanctum tokens)
- Built assets are committed, so the server needs no Node

## PRs (stacked drafts — merge in this order)
| PR | Branch | Status |
|----|--------|--------|
| 1 | `pr/01-scaffold` | Draft. Laravel 13, Docker, CI, docs |
| 2 | `pr/02-platform-core` | Draft. Feature switches, settings, roles, activity log, admin 2FA ([docs/platform.md](docs/platform.md)) |
| 3 | `pr/03-money-ledger` | Draft. Double-entry ledger, wallet buckets, idempotency ([docs/ledger.md](docs/ledger.md)) |
| 4 | `pr/04-catalog` | Draft. Products, variants, images, early access, members-only, cost & 2.5× warning ([docs/catalog.md](docs/catalog.md)) |
| 5 | `pr/05-pricing` | Draft. Member price, coupons, free shipping, Join & save, profit check ([docs/pricing.md](docs/pricing.md)) |
| 6 | `pr/06-cart-checkout` | Draft. Cart, guest checkout, tracking, unpaid auto-cancel ([docs/checkout.md](docs/checkout.md)) |
| 7 | `pr/07-shipping-notify` | Draft. Zones, couriers, status flow, SMS/email/Telegram ([docs/shipping-notifications.md](docs/shipping-notifications.md)) |
| 8 | `pr/08-payments` | Draft. Chapa, manual proof, exactly-once, refunds, receipts ([docs/payments.md](docs/payments.md)) |
| 9 | `pr/09-membership` | Draft. Accounts, membership, grace, reminders, loyalty ([docs/membership.md](docs/membership.md)) |
| 10 | `pr/10-signals` | Draft. Signals, Telegram bot access control, locked results ([docs/signals.md](docs/signals.md)) |
| 11 | `pr/11-referrals` | Draft. 3-level commissions, link/code, locked referrer ([docs/referrals.md](docs/referrals.md)) |
| 12 | `pr/12-wallet-cashout` | Draft. Wallet spending, cash-out, fraud queue ([docs/wallet-cashout-fraud.md](docs/wallet-cashout-fraud.md)) |
| 13 | `pr/13-engagement` | Draft. Badges, top referrers, QR/share |
| 14 | `pr/14-api-v1` | Draft. Account API, SMS reset, endpoint list ([docs/api.md](docs/api.md)) |
| 15 | `pr/15-admin-shell` | Draft. React admin shell, 2FA login, switches, settings ([docs/admin.md](docs/admin.md)) |
| 16 | `pr/16-admin-shop` | Draft. Admin: orders, payments, products, coupons, shipping, members |
| 17 | `pr/17-admin-money` | Draft. Admin: cash-outs, ledger, fraud, commissions, tree, signals |
| 18 | `pr/18-admin-dashboards` | Draft. KPI dashboard, system status |
| 19 | `pr/19-storefront` | Draft. Storefront, page cache, SEO, member area ([docs/storefront.md](docs/storefront.md)) |
| 20 | `pr/20-texts-golive` | Draft. Default texts, CSP, encrypted backups, `km:doctor`, deploy guide ([docs/deploy.md](docs/deploy.md)) |
| 21 | `pr/21-logic-review` | Draft. Review: Larastan in CI, money edge-case tests (replays, double clicks, races, bad input), feature-switch matrix, jobs pause when off |
| 22 | `pr/22-account-security` | Draft. Account security: password rules + common list, 10-try pause, customer 2FA, 'Was this you?' notices, email change by link, admin idle timeout, sign out everywhere ([docs/account-security.md](docs/account-security.md)) |
| 23 | `pr/23-money-safety` | Draft. Money safety: saved payout account + 48 h wait + verification, daily limits, idempotent cash-outs, Chapa double-click reuse; fake-account checks, sign-up limits |

**Merge order:** 1 → 2 → … → 20. Each PR targets the previous branch. After
merging one into `main`, change the next PR's base to `main` (GitHub offers
this automatically), or merge the top PR #20 into `main` once you're happy
with the whole stack.

## Key decisions
All decisions are in [QUESTIONS.md](QUESTIONS.md). The important ones:
- Money is integer santim and double-entry. Balances are never stored.
  Discounts round half-up; commissions round down.
- Commissions: only active members (incl. grace) earn, inactive levels
  are skipped (not passed up), 14 days pending → available, refunds
  reverse them (even after release).
- Guests get no session or IP storage. The only cookies are `km_cart` and
  `km_ref`.
- Admin requires 2FA. Staff can't get API tokens.
- Signals access goes through a personal deep link bound to one Telegram
  account, plus join-request approval, with automatic removal.

## How to run locally
See [docs/local-setup.md](docs/local-setup.md).

## Test status
- 267 backend tests pass on SQLite and MariaDB 10.11 (every money path in
  the brief is covered).
- The admin passes typecheck, lint (0 errors) and build. The storefront
  builds.
- CI (on the top PR): Pint + PHPUnit on MySQL 8, admin
  typecheck/lint/build, and Lighthouse ≥ 95 + LCP ≤ 1.5 s.
- Layout checked in headless Chrome (15 Nov): every admin page and every
  storefront page at 1440 px and 390 px, plus the sign-in/2FA screens at
  610 px. No page scrolls sideways; wide admin tables scroll inside their box.

## Not done / next ideas
- Larastan isn't installed (the build machine couldn't download it). Add
  it with `composer require --dev larastan/larastan`.
- Amharic: all texts go through settings, pages or Blade, so adding `lang/am`
  plus a language switch is the next step.
- Product reviews: the `shop.reviews` switch exists (off), but the feature
  isn't built.
- Flutter app: the API is ready (`docs/api.md`).

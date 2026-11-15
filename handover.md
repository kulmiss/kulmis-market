# Handover — Kulmis Market

_Last updated: PR 34._

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
| 36 | `pr/36-green-logo` | **Top PR, targets main, ready for review, CI runs here.** Logo in the member-area green (#1f8a4c) everywhere: storefront, member area, errors, admin, emails, PDF receipts, favicon/ico/touch/app icons, social preview; manifest theme colour green ([docs/storefront.md](docs/storefront.md#logo-and-icons)) |
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
| 24 | `pr/24-access-control` | Draft. Access control: every route × every role test, IDOR tests, 'approve-payments' ability for transfers |
| 25 | `pr/25-web-security` | Draft. Web security: headers also from nginx/Apache, CORS, security.txt, per-route rate limits, proof re-encoding, log scrubbing, gitleaks + audits in CI, owner alerts, log retention ([docs/web-security.md](docs/web-security.md)) |
| 26 | `pr/26-ops` | Draft. Ops: Docker runs PHP as your user, port 8090, pinned images, Dependabot, km:doctor security checks (fails when unsafe), km:backup-verify restore test, optional admin IP lock, production checklist ([docs/deploy.md](docs/deploy.md)) |
| 27 | `pr/27-screens` | Draft. Screen review: branded error pages, dark-mode badge, admin logo, phone overflow, labels, heading order, gzip; Lighthouse before/after |
| 28 | `pr/28-security-report` | **Merged into main.** SECURITY_REPORT.md: audit, scores before → after, remaining risks |

PRs 1–28 are merged into `main`.

### Round 2 (stacked on `main`)
| PR | Branch | Status |
|----|--------|--------|
| 29 | `pr/29-referral-fix` | Draft (#37). Bug #7 "referral not working": same-browser sign-ups are linked + held for review (were silently refused), soft network flag, local sign-up limit, referral QR no longer 404s under nginx; end-to-end tests A→B→C→D + guest via link + admin tree == member team |
| 30 | `pr/30-currency` | Draft (#38). Currency setting (Settings → General): code, symbol, position, decimals, separators; used everywhere; confirmation, nothing converted; Chapa blocked for unsupported currencies ([docs/platform.md](docs/platform.md#currency-settings--general)) |
| 31 | `pr/31-badges` | Draft (#39). Badges: 21 editable badges (team, total team, loyalty, first sale, earnings, top referrer, early supporter, shopper) with progress bars, admin editor, unlock celebration + in-app notifications ([docs/badges.md](docs/badges.md)) |
| 32 | `pr/32-logo` | Draft (#40). New logo (A, smiling mesob) everywhere: storefront, errors, admin, emails, PDF receipts, favicon/ico/touch/manifest icons, social preview ([docs/storefront.md](docs/storefront.md#logo-and-icons)) |
| 33 | `pr/33-demo-data` | Draft (#41). Demo data for every section (6 months simulated through the real services, real Pexels photos + CREDITS.md), `is_demo` everywhere, Show demo data switch, Delete all demo data, never mixed into real totals, refuses production ([docs/local-setup.md](docs/local-setup.md#demo-data)) |
| 34 | `pr/34-member-dashboard` | Draft (#42). New member area: app-like layout (sidebar / bottom bar), green & white + dark mode, accent in Settings → Appearance; home with money cards, month vs month, chart, per level/type, team, newest, badge, referral tools, membership + streak, signals, activity; Team, Earnings, Wallet, Orders, Signals pages; empty states ([docs/storefront.md](docs/storefront.md#member-area-account)) |
| 35 | `pr/35-remove-chapa` | Draft (#43). Chapa removed completely: payments are Telebirr/bank transfer + receipt (staff approve) and the wallet; membership = wallet or transfer (+ part wallet); guest signals = transfer + receipt; manual/wallet refunds; CSP `form-action 'self'`; page texts updated by a migration ([docs/payments.md](docs/payments.md)) |

**Merge:** the top PR of round 2 targets `main` and contains every commit
below it, so one merge brings in everything.

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
- Payments: transfer + receipt (staff approve) and the wallet. No Chapa
  or other online gateway.
- Signals access goes through a personal deep link bound to one Telegram
  account, plus join-request approval, with automatic removal.

## How to run locally
See [docs/local-setup.md](docs/local-setup.md).

## Test status
- 333 backend tests pass on SQLite and MariaDB 10.11 (every money path in
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

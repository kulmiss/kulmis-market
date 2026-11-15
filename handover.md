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

## PRs (everything up to #37 is merged into main; #38–#44 are a stack — merge the top one)
| PR | Branch | Status |
|----|--------|--------|
| 1 | `pr/01-scaffold` | Merged. Laravel 13, Docker, CI, docs |
| 2 | `pr/02-platform-core` | Merged. Feature switches, settings, roles, activity log, admin 2FA ([docs/platform.md](docs/platform.md)) |
| 3 | `pr/03-money-ledger` | Merged. Double-entry ledger, wallet buckets, idempotency ([docs/ledger.md](docs/ledger.md)) |
| 4 | `pr/04-catalog` | Merged. Products, variants, images, early access, members-only, cost & 2.5× warning ([docs/catalog.md](docs/catalog.md)) |
| 5 | `pr/05-pricing` | Merged. Member price, coupons, free shipping, Join & save, profit check ([docs/pricing.md](docs/pricing.md)) |
| 6 | `pr/06-cart-checkout` | Merged. Cart, guest checkout, tracking, unpaid auto-cancel ([docs/checkout.md](docs/checkout.md)) |
| 7 | `pr/07-shipping-notify` | Merged. Zones, couriers, status flow, SMS/email/Telegram ([docs/shipping-notifications.md](docs/shipping-notifications.md)) |
| 8 | `pr/08-payments` | Merged. Chapa, manual proof, exactly-once, refunds, receipts ([docs/payments.md](docs/payments.md)) |
| 9 | `pr/09-membership` | Merged. Accounts, membership, grace, reminders, loyalty ([docs/membership.md](docs/membership.md)) |
| 10 | `pr/10-signals` | Merged. Signals, Telegram bot access control, locked results ([docs/signals.md](docs/signals.md)) |
| 11 | `pr/11-referrals` | Merged. 3-level commissions, link/code, locked referrer ([docs/referrals.md](docs/referrals.md)) |
| 12 | `pr/12-wallet-cashout` | Merged. Wallet spending, cash-out, fraud queue ([docs/wallet-cashout-fraud.md](docs/wallet-cashout-fraud.md)) |
| 13 | `pr/13-engagement` | Merged. Badges, top referrers, QR/share |
| 14 | `pr/14-api-v1` | Merged. Account API, SMS reset, endpoint list ([docs/api.md](docs/api.md)) |
| 15 | `pr/15-admin-shell` | Merged. React admin shell, 2FA login, switches, settings ([docs/admin.md](docs/admin.md)) |
| 16 | `pr/16-admin-shop` | Merged. Admin: orders, payments, products, coupons, shipping, members |
| 17 | `pr/17-admin-money` | Merged. Admin: cash-outs, ledger, fraud, commissions, tree, signals |
| 18 | `pr/18-admin-dashboards` | Merged. KPI dashboard, system status |
| 19 | `pr/19-storefront` | Merged. Storefront, page cache, SEO, member area ([docs/storefront.md](docs/storefront.md)) |
| 20 | `pr/20-texts-golive` | Merged. Default texts, CSP, encrypted backups, `km:doctor`, deploy guide ([docs/deploy.md](docs/deploy.md)) |
| 21 | `pr/21-logic-review` | Merged. Review: Larastan in CI, money edge-case tests (replays, double clicks, races, bad input), feature-switch matrix, jobs pause when off |
| 22 | `pr/22-account-security` | Merged. Account security: password rules + common list, 10-try pause, customer 2FA, 'Was this you?' notices, email change by link, admin idle timeout, sign out everywhere ([docs/account-security.md](docs/account-security.md)) |
| 23 | `pr/23-money-safety` | Merged. Money safety: saved payout account + 48 h wait + verification, daily limits, idempotent cash-outs, Chapa double-click reuse; fake-account checks, sign-up limits |
| 24 | `pr/24-access-control` | Merged. Access control: every route × every role test, IDOR tests, 'approve-payments' ability for transfers |
| 25 | `pr/25-web-security` | Merged. Web security: headers also from nginx/Apache, CORS, security.txt, per-route rate limits, proof re-encoding, log scrubbing, gitleaks + audits in CI, owner alerts, log retention ([docs/web-security.md](docs/web-security.md)) |
| 26 | `pr/26-ops` | Merged. Ops: Docker runs PHP as your user, port 8090, pinned images, Dependabot, km:doctor security checks (fails when unsafe), km:backup-verify restore test, optional admin IP lock, production checklist ([docs/deploy.md](docs/deploy.md)) |
| 27 | `pr/27-screens` | Merged. Screen review: branded error pages, dark-mode badge, admin logo, phone overflow, labels, heading order, gzip; Lighthouse before/after |
| 28 | `pr/28-security-report` | **Merged into main.** SECURITY_REPORT.md: audit, scores before → after, remaining risks |

PRs 1–28 are merged into `main`.

### Round 2 (stacked on `main`)
| PR | Branch | Status |
|----|--------|--------|
| 29 | `pr/29-referral-fix` | Merged via #44 (#37). Bug #7 "referral not working": same-browser sign-ups are linked + held for review (were silently refused), soft network flag, local sign-up limit, referral QR no longer 404s under nginx; end-to-end tests A→B→C→D + guest via link + admin tree == member team |
| 30 | `pr/30-currency` | Merged via #44 (#38). Currency setting (Settings → General): code, symbol, position, decimals, separators; used everywhere; confirmation, nothing converted; Chapa blocked for unsupported currencies ([docs/platform.md](docs/platform.md#currency-settings--general)) |
| 31 | `pr/31-badges` | Merged via #44 (#39). Badges: 21 editable badges (team, total team, loyalty, first sale, earnings, top referrer, early supporter, shopper) with progress bars, admin editor, unlock celebration + in-app notifications ([docs/badges.md](docs/badges.md)) |
| 32 | `pr/32-logo` | Merged via #44 (#40). New logo (A, smiling mesob) everywhere: storefront, errors, admin, emails, PDF receipts, favicon/ico/touch/manifest icons, social preview ([docs/storefront.md](docs/storefront.md#logo-and-icons)) |
| 33 | `pr/33-demo-data` | Merged via #44 (#41). Demo data for every section (6 months simulated through the real services, real Pexels photos + CREDITS.md), `is_demo` everywhere, Show demo data switch, Delete all demo data, never mixed into real totals, refuses production ([docs/local-setup.md](docs/local-setup.md#demo-data)) |
| 34 | `pr/34-member-dashboard` | Merged via #44 (#42). New member area: app-like layout (sidebar / bottom bar), green & white + dark mode, accent in Settings → Appearance; home with money cards, month vs month, chart, per level/type, team, newest, badge, referral tools, membership + streak, signals, activity; Team, Earnings, Wallet, Orders, Signals pages; empty states ([docs/storefront.md](docs/storefront.md#member-area-account)) |
| 35 | `pr/35-remove-chapa` | Merged via #44 (#43). Chapa removed completely: payments are Telebirr/bank transfer + receipt (staff approve) and the wallet; membership = wallet or transfer (+ part wallet); guest signals = transfer + receipt; manual/wallet refunds; CSP `form-action 'self'`; page texts updated by a migration ([docs/payments.md](docs/payments.md)) |
| 36 | `pr/36-green-logo` | Merged (#44). Logo in the member-area green (#1f8a4c) everywhere: storefront, member area, errors, admin, emails, PDF receipts, favicon/ico/touch/app icons, social preview; manifest theme colour green ([docs/storefront.md](docs/storefront.md#logo-and-icons)) |
| 37 | `pr/37-green-store` | Merged (#45). Shop in white & green (same green as the logo/member area; dark mode readable; emails, PDF, social preview match); no religious images in the demo data (cross pendant and Meskel print replaced, evil-eye beads cropped) ([docs/storefront.md](docs/storefront.md#colours)) |
| 38 | `pr/38-deps` | Draft (#46). Dependency updates: GitHub Actions (checkout 7, cache 6, setup-node 7), admin @tanstack/react-table 9 (tables migrated to the v9 API), react-day-picker 10, @types/node 26, prettier-plugin-tailwindcss 0.8; unused table helpers removed; Dependabot skips PHP 8.4+, phpunit 13 and TypeScript 7 (QUESTIONS #59) |
| 39 | `pr/39-host-assets` | Draft (#47). Host-header cache poisoning fixed (canonical URLs, host check, trusted hosts); committed CSS rebuilt; CI checks committed builds match the sources |
| 40 | `pr/40-header-theme` | Draft (#48). Header: theme, cart, account at the far right showing the signed-in member; Super Admin light/dark switch; no stale guest page after sign-in |
| 41 | `pr/41-bugs` | Draft (#49). Bug hunt: Finance can open Payments, one receipt under review at a time, role-aware admin (403 / first allowed page), feature state for all staff, red errors, double-submit protection, control-character stripping, pay-from-wallet takes nothing unless full |
| 42 | `pr/42-security` | Draft (#50). Security re-audit: AttackTest (XSS, query strings, mass assignment, redirects, enumeration, stock), array query strings no longer crash, membership API fix, storefront CSP without inline styles, Larastan level 6 |
| 43 | `pr/43-perf` | Draft (#51). Speed: speculation rules, LCP preload, lighter photos, service worker + offline page, fewer queries, cached dashboard, skeletons, deploy clears the page cache, CI budgets (phone Lighthouse, server time) |
| 44 | `pr/44-report` | **Top PR, targets main, ready for review, CI runs here.** SECURITY_REPORT round 3 (scores, every bug, speed before → after), docs, handover |

**Merge:** the top PR targets `main` and contains every commit
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
- 358 backend tests pass on SQLite and MariaDB 10.11 (every money path in
  the brief is covered).
- The admin passes typecheck, lint (0 errors) and build. The storefront
  builds.
- CI (on the top PR): Pint, Larastan level 6, PHPUnit on MySQL 8, admin
  typecheck/lint/build, committed builds match the sources, server-time
  budgets, Lighthouse desktop (≥ 95, LCP ≤ 1.0 s) and phone (LCP ≤ 1.8 s,
  slow 4G), gitleaks.
- Round 3 review (15 Nov): every screen and flow re-tested in a browser as
  every member type and staff role, attack tests and real parallel requests;
  19 bugs found and fixed. See SECURITY_REPORT.md.
- Layout checked in headless Chrome (15 Nov): every admin page and every
  storefront page at 1440 px and 390 px, plus the sign-in/2FA screens at
  610 px. No page scrolls sideways; wide admin tables scroll inside their box.

## Not done / next ideas
- Amharic: all texts go through settings, pages or Blade, so adding `lang/am`
  plus a language switch is the next step.
- Product reviews: the `shop.reviews` switch exists (off), but the feature
  isn't built.
- Flutter app: the API is ready (`docs/api.md`).

# Handover — Kulmis Market

_Last updated: Round 6 (switched off = never existed; top branch `pr/74-admin-off`)._

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

## PRs (1–44 are merged into main; everything newer is one stack — merge the top PR)

After every merge into `main`, the **Close PRs already in main** workflow closes open PRs that have nothing new left (for example the lower PRs of a stack). It never closes a PR with new changes. Script: `scripts/close-merged-prs.sh` (`--dry-run` to only list).

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
| 38 | `pr/38-deps` | Merged via #52. Dependency updates: GitHub Actions (checkout 7, cache 6, setup-node 7), admin @tanstack/react-table 9 (tables migrated to the v9 API), react-day-picker 10, @types/node 26, prettier-plugin-tailwindcss 0.8; unused table helpers removed; Dependabot skips PHP 8.4+, phpunit 13 and TypeScript 7 (QUESTIONS #59) |
| 39 | `pr/39-host-assets` | Merged via #52. Host-header cache poisoning fixed (canonical URLs, host check, trusted hosts); committed CSS rebuilt; CI checks committed builds match the sources |
| 40 | `pr/40-header-theme` | Merged via #52. Header: theme, cart, account at the far right showing the signed-in member; Super Admin light/dark switch; no stale guest page after sign-in |
| 41 | `pr/41-bugs` | Merged via #52. Bug hunt: Finance can open Payments, one receipt under review at a time, role-aware admin (403 / first allowed page), feature state for all staff, red errors, double-submit protection, control-character stripping, pay-from-wallet takes nothing unless full |
| 42 | `pr/42-security` | Merged via #52. Security re-audit: AttackTest (XSS, query strings, mass assignment, redirects, enumeration, stock), array query strings no longer crash, membership API fix, storefront CSP without inline styles, Larastan level 6 |
| 43 | `pr/43-perf` | Merged via #52. Speed: speculation rules, LCP preload, lighter photos, service worker + offline page, fewer queries, cached dashboard, skeletons, deploy clears the page cache, CI budgets (phone Lighthouse, server time) |
| 44 | `pr/44-report` | Merged (#52). SECURITY_REPORT round 3 (scores, every bug, speed before → after), docs, handover |
| 45 | `pr/45-switches` | Draft (#53). Feature switches for every feature (order tracking, PDF receipts, sign-up, customer 2FA, share buttons, QR codes, instant loading, offline page, each message type, each badge), sections with easy descriptions, search and on/off overview; queue workers see changes at once ([docs/platform.md](docs/platform.md#feature-switches)) |
| 46 | `pr/46-bonus` | Draft (#54). Monthly badge bonus (Bronze 200 … Diamond 6,000 ETB), highest badge only, paid only for fully paid months; profit guards: 2% of the member's own team money, 5% monthly budget with pro-rata cut, 35% settings guard; dry run, pay now / skip, first payout by hand; member card; simulation test ([docs/badge-bonus.md](docs/badge-bonus.md)) |
| 47 | `pr/47-tables` | Draft (#55). One data table everywhere (admin + member area): search, sort, filters, columns, page size, selection + bulk, CSV, sticky header, saved per table, server paging, cards on phones ([docs/admin.md](docs/admin.md#data-tables-every-list)) |
| 48 | `pr/48-mobile` | Draft (#56). Mobile first at 360/390/414: one column on phones, 48 px touch targets, readable charts, filters/sort/badge groups folded with a count (remembered) |
| 49 | `pr/49-search` | Draft (#57). Global search Ctrl/⌘K: grouped, role-aware, #ID / exact phrase / phone formats, advanced filters, recent searches, quick actions, ~15 ms ([docs/admin.md](docs/admin.md#search-everything-ctrl-k)) |
| 50 | `pr/50-easy-english` | Draft (#58). Very easy English in admin, member area and messages; Money history instead of Ledger; [docs/glossary.md](docs/glossary.md) |
| 51 | `pr/51-report` | **Top PR, targets main, ready for review, CI runs here.** Round 4 report, handover, merge and run steps |

**Merge:** the top PR targets `main` and contains every commit
below it, so one merge brings in everything.

## Round 4 (feature switches, badge bonus, tables, mobile, search, easy English)

- **Feature switches**: every feature has one, grouped with easy descriptions, search and an on/off overview. Queue workers now see changes at once.
- **Badge bonus**: see [docs/badge-bonus.md](docs/badge-bonus.md). You keep at least 3,983 of every 5,000 ETB membership (worst case 20.34% paid out; settings can never go over 35%). The job on the 1st waits for your first "Pay now".
- **Tables**: one data table in the admin (`components/km/data-table.tsx`) and in the member area (`App\Member\MemberTable`).
- **Phones**: checked at 360/390/414 px, no sideways scrolling, 48 px targets.
- **Search**: Ctrl/⌘K, role-aware, about 15 ms.
- **Words**: [docs/glossary.md](docs/glossary.md).

## Round 5 (new ways to earn, then a full review)

One stack on top of Round 4 (#59): #60 → #74. The top PR targets `main`, so one merge brings in Round 4 and Round 5.

| PR | Branch | What |
|----|--------|------|
| 60 | `pr/52-wishlist` | Wishlist, back-in-stock alerts, saved addresses, buy again |
| 61 | `pr/53-reviews` | Product reviews from verified buyers |
| 62 | `pr/54-flash` | Flash sales, bundles, "bought together" and one-tap upsells |
| 63 | `pr/55-notify` | Bell (notification centre), monthly goals, cart reminder |
| 64 | `pr/56-help` | Signal results chart and notes per pair; help centre with contact form |
| 65 | `pr/57-pwa` | Member app (install on the phone) and phone notifications |
| 66 | `pr/58-rewards` | Rewards: first-payment bonus (250 ETB), welcome-back reward (0 = off), profit guard counts them |
| 67 | `pr/59-contests` | Referral contests and team challenges (paid after the 14-day check, inside their budgets) |
| 68 | `pr/60-share` | Product share links, share kit, picture cards, tips |
| 69 | `pr/61-gift` | Gift membership |
| 70 | `pr/62-extras` | Checkout extras (off), cash-out fee (off), gift cards |
| 71 | `pr/63-digital` | Digital products and paid add-ons |
| 72 | `pr/64-deposits` | Pre-orders, custom orders with a deposit, win-back offers |
| 73 | `pr/65-reports` | Reports (CSV/PDF), bulk edit + CSV import, low-stock alerts, announcement colours, staff overview |
| 74 | `pr/66-review` | Full review: bug fixes, demo data for new features, speed, security, docs |

- **Profit**: every new payout goes through the ledger, the 14-day "on the way" period, fraud holds and ProfitGuard. Worst case with every payout on: 25.34% paid out of a 5,000 ETB membership (you keep 3,733 ETB); a running contest adds up to 2% and challenges up to 2% (29.34%, keep 3,533 ETB). Settings can never go over 35%. The random-teams simulation (all payouts, contests and challenges on) kept 81.6–84.2% of membership money.
- **Review**: see SECURITY_REPORT.md (Round 5) and QUESTIONS.md #123–#133.

## Round 6 (sign-out, 2FA check, courier cost, switched off = never existed)

One stack on `main`: `pr/67-auto-close` (#75) → `pr/74-admin-off`. The top PR targets `main`, so one merge brings in all of it.

| Branch | What |
|--------|------|
| `pr/67-auto-close` | After a merge into main, open PRs with nothing new are closed automatically |
| `pr/68-signout` | "Do you want to sign out?" [Sign out] [Cancel] everywhere, then "You are signed out" |
| `pr/69-hide-off` | First pass: notification options, menus and levels of switched-off features hidden |
| `pr/70-2fa` | Staff must use two-step sign-in on the live shop; customer 2FA optional (tests per role) |
| `pr/71-courier` | Courier cost per city in the price check and the order simulation; free delivery never loses money |
| `pr/72-never-existed` | Switched off = never existed: words, links, pages (404), API texts, messages, earnings, badges; crawl test for every switch |
| `pr/73-paid-access` | People who paid keep membership/signals until their month ends; End access now with money back for unused days |
| `pr/74-admin-off` | Admin: preview before switching off, "Switched-off features" menu group with history, text warnings, page texts with {if …} |

- **How it is checked**: `FeatureNeverExistedTest` turns every switch off one at a time, crawls the shop as a guest, a member with a team, a new member, an expired member and a shopper (plus sitemap, robots, manifests, offline page, 404 page, API, message templates), fails on any word/link/page of that feature, then switches it back on and checks the words come back. A new feature needs its words in `config/feature_words.php`.
- **Decisions**: QUESTIONS.md #135–#148.

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
- Round 6: 535 backend tests pass on SQLite and MariaDB (Pint, Larastan level 6). The never-existed crawl takes about 2 minutes.
- Round 5: 507 backend tests pass on SQLite and MariaDB (Pint, Larastan level 6, audits and gitleaks clean). Every screen re-checked at 360/390/414 px and desktop, light and dark, as 6 member types and 5 staff roles; new flows (gift card, download, gift membership, custom order, bell, contests, share) run in a phone-size browser; real parallel requests for flash-sale stock, gift cards and download limits; a product-order simulation (270 random orders) proves no paid order loses money.
- Round 4: 424 backend tests passed on SQLite and MariaDB 10.11 (every money path in
  the brief is covered, plus the badge bonus simulation with 2,500 random
  members in 4 set-ups).
- The admin passes typecheck, lint (0 errors) and build. The storefront
  builds.
- CI (on the top PR): Pint, Larastan level 6, PHPUnit on MySQL 8, admin
  typecheck/lint/build, committed builds match the sources, server-time
  budgets, Lighthouse desktop (≥ 95, LCP ≤ 1.0 s) and phone (LCP ≤ 2.5 s in CI,
  slow 4G), gitleaks.
- Round 3 review (15 Nov): every screen and flow re-tested in a browser as
  every member type and staff role, attack tests and real parallel requests;
  22 bugs found and fixed. See SECURITY_REPORT.md.
- Round 4 (15 Nov): shop, member area and admin checked in a headless
  browser at 360/390/414 px (no sideways scrolling, touch targets) and on
  desktop; screenshots sent with the round-4 report.
- Layout checked in headless Chrome (15 Nov): every admin page and every
  storefront page at 1440 px and 390 px, plus the sign-in/2FA screens at
  610 px. No page scrolls sideways; wide admin tables scroll inside their box.

## Not done / next ideas
- Amharic: all texts go through settings, pages or Blade, so adding `lang/am`
  plus a language switch is the next step.
- Flutter app: the API is ready (`docs/api.md`).

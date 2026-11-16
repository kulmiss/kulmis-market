# Kulmis Market — security and quality review

_Newest review first: Round 5, then Round 3, then round 1 (before the first merge)._

## Round 5 — review of the new earning features (16 Nov 2026)

Branch `pr/66-review` (PR #74), on top of the Round 5 features (#60–#73).

**How it was checked**
- **Every screen in a real browser** (headless Chrome, Docker + nginx + MySQL 8, port 8090, demo data that now includes every new feature):
  - 360, 390 and 414 px and desktop, light and dark
  - as a guest, a member with a big team, a new member, an expired member, a member in grace and a shopper, and as all 5 staff roles
  - checks: console errors, failed requests, broken images, sideways scrolling, unlabeled fields, PHP warnings
- **New flows in a phone-size browser:** buy a gift card; flash-sale product → cart → gift card code → checkout → pay page; custom order; gift membership → approval → the receiver starts it; buy a download → approval → download; bell, contests, share kit and picture card. Double clicks everywhere, Amharic, emoji and HTML in fields.
- **Real parallel requests against MySQL:** 10 buyers for the last 2 flash-sale pieces (exactly 2 at the sale price), 4 checkouts with one 500 ETB gift card (the card paid once, balance never below 0), 12 clicks on a 3-use download link (exactly 3 downloads), cash-outs. Ledger total = 0 after every run.
- **Attacks (new tests):** admin-typed text shown on member and shop pages (XSS), extra fields in admin forms (mass assignment), every admin route × every role (now every `can:` rule), a gift link used by two people, a payment event seen twice.
- **Money:** a new product-order simulation (3 × 90 random orders) and the membership simulation with every new payout on.
- **Tools:** Pint, Larastan level 6 (0 errors), `composer audit`, `npm audit` (store + admin) clean, gitleaks (88 commits) no leaks, Lighthouse (22 runs + 20 repeat runs), query counts and server timings.

Result: **507 tests** pass on SQLite and MariaDB.

### Scores (Round 5)

| Area | Bugs found | Fixed? | Score /10 before → after |
|---|---|---|---|
| Storefront | **/shop and / crashed when a flash sale or bundle was live** (server cache refuses objects); `/r/CODE/shop` missing; pay page didn't say what the gift card paid; on a sale, "Members pay" used the normal price and "only N pieces" ignored pieces already sold | ✅ | 6.5 → 9.5 |
| Money & payments | **Buying a gift card from the form always failed**; a digital product could show "Sold out"; download-only orders asked for an address | ✅ | 7.5 → 9.5 |
| Profit guards | **A big coupon on top of member/sale prices could make an order lose money** (up to ~94 ETB) | ✅ Coupon safety | 8 → 9.5 |
| Access control | **Fulfilment and Support could make gift cards for cash** (money to spend); the role test only read the first rule | ✅ | 8 → 9.5 |
| Admin screens | Template "My account" page (fake Display tab, raw-data pop-up, hard words, sideways scroll on phones, unlabeled radios); dashboard asked for by roles without it (403) ; 401 on the sign-in page | ✅ | 8 → 9.5 |
| Performance | Flash sales read the cache once per product card (32 queries on /shop → 8); cart badge sometimes delayed the first paint on phones (+0.5 s); member-only script made the shop's first download bigger (phone LCP budget in CI missed by 50 ms) | ✅ | 8.5 → 9.5 |
| Concurrency (stock, gift cards, downloads, rewards) | none (verified with real parallel requests; rewards have a row lock + unique key) | — | 9.5 → 9.5 |
| Web security (XSS, CSRF, headers, CSP, CORS) | none new (honeypot fields got a label) | ✅ | 9.5 → 9.5 |
| Ops | Slow-query log only in development; demo data had none of the new features | ✅ | 8.5 → 9.5 |
| Dependencies & secrets | none | — | 9.5 → 9.5 |

### Speed (Docker + nginx, demo data; before = Round 4 build, after = this branch)

| Page / action | Before | After |
|---|---|---|
| Cached pages (/, /shop, product, /membership, /signals) | 0.5–0.8 ms | 0.3–0.5 ms |
| / (not cached) | 25.5 ms | 19.5 ms |
| /shop (not cached) | 25.4 ms | 25.0 ms (with sale badges and bundles) |
| Product page (not cached) | 25.1 ms | 25.3 ms |
| /membership (not cached) | 15.2 ms | 10.7 ms |
| API products / categories / one product (median) | 30.2 / 16.4 / 22.5 ms | 22.5 / 10.7 / 14.5 ms |
| Member home /account | 101 ms | 81–91 ms |
| Team / earnings / wallet / orders | 56 / 39 / 37 / 25 ms | 43–47 / 29 / 23 / 16 ms |
| New member pages: contests / share / bell | — | 23 / 16 / 17 ms |
| Admin API (every list, reports, search) p95 | — | ≤ 60 ms (dashboard first load 91 ms) |
| Lighthouse phone + desktop (perf / a11y / best practices / SEO) | 100 / 96–100 / 100 / 100 | **100 / 100 / 100 / 100** on all 22 runs |
| Phone LCP, text pages (5 runs each) | 0.94–0.96 s | 0.95–1.17 s (target 1.2 s) |
| CLS / tap response (4× slower CPU) | 0 / — | 0 / ≤ 40 ms |
| Store JS + CSS (gzip) | 5 + 9 KB | 7.1 + 9.8 KB (member-only parts, about 1 KB each, load only where used) |
| Admin first download (gzip) | ~170 KB | ~170 KB (new pages load on demand) |

### Not done, or only partly, and why
- **Members' free delivery** (over 3,000 ETB) is your existing choice: the shop pays the courier on those orders. The profit checks leave delivery out (the delivery charge pays the courier and its share of the payment fee).
- **Wallet balances** are computed 3 times on the member home (about 1 ms each); not cached inside the page so a balance is never wrong right after a cash-out.
- **Real phones:** checked with phone-size headless Chrome and touch emulation, not on physical devices.

## Round 3 — second full review (15 Nov 2026, after round 2 + the Chapa removal)

Branches `pr/38` … `pr/44` (PRs #46–#52). Goal: bulletproof and feeling instant.

**How it was checked**
- **Every screen in a real browser** (headless Chrome against Docker + nginx, port 8090, demo data):
  - storefront, member area and admin, on phone and desktop, light and dark
  - as a guest, a new member, a member with a big team, a member in grace, an expired member and a shopper without membership
  - as every staff role and as Super Admin
  - the checks: console errors, failed requests, broken images, sideways scrolling, unlabeled fields and PHP warnings
- **End-to-end flows in the browser:**
  - browse → cart → checkout → transfer receipt → staff approval → track
  - membership from the wallet, cash-out, referral QR, signals
  - double clicks, the back button, refresh mid-payment, expired sessions
  - emoji, Amharic and HTML in every field, huge input
- **Attacks:** a new `AttackTest` covering:
  - stored XSS everywhere
  - array/huge query values on every GET route
  - mass assignment
  - open redirects
  - account enumeration
  - the last item in stock
- **Real parallel requests against MariaDB:**
  - 10 buyers for the last item
  - 6 checkouts with a single-use coupon
  - 8 cash-outs at once
  - each one succeeded exactly once
- **Tools:**
  - Larastan raised to **level 6**
  - `composer audit` and `npm audit` (storefront + admin): clean
  - gitleaks in CI
  - Lighthouse (12 runs, phone + desktop) before → after
  - query counts per page, server timings (p95)

Result: **358 tests** pass on SQLite and MariaDB; Larastan level 6 with 0 errors; audits clean.

### Scores (round 3)

| Area | Bugs found | Fixed? | Score /10 before → after |
|---|---|---|---|
| Web security | Host-header **cache poisoning** (high); array query strings crashed pages | ✅ | 8 → 9.5 |
| Money & payments | Finance couldn't open Payments; **double "Send receipt" made two approvable receipts**; "pay from wallet" with too little took the partial amount | ✅ | 8 → 9.5 |
| Access control (staff roles) | Pages a role can't use showed empty tables or loaded forever; staff couldn't read feature on/off | ✅ | 8.5 → 9.5 |
| Storefront | Stale guest page after sign-in; errors shown in green; tracking asked to pay again while a receipt was checked; back button showed the old cart | ✅ | 8 → 9.5 |
| API robustness | `GET /api/v1/membership` crashed for people who never joined | ✅ | 8.5 → 9.5 |
| Input hygiene | Null bytes / right-to-left overrides were stored | ✅ | 9 → 9.5 |
| Build & deploy | Committed CSS older than its source; deploys served cached pages pointing at deleted CSS/JS | ✅ (+ CI check) | 7.5 → 9.5 |
| CSP | Storefront allowed inline `<style>` blocks | ✅ (attributes only) | 9 → 9.5 |
| Concurrency (stock, coupons, wallet) | none (verified with real parallel requests) | — | 9.5 → 9.5 |
| Accessibility | Button contrast 4.37:1 (stale CSS), prohibited ARIA in shop pagination, ~50 admin fields without an accessible name | ✅ | 9 → 9.5 |
| Code quality | 26 type issues at Larastan level 6 | ✅ | 9 → 9.5 |
| Performance | N+1 on member pages, uncached dashboard, heavy photos; the new dashboard cache broke lists on the second load (caught by the final check) | ✅ | 9 → 9.5 |
| Dependencies | 8 Dependabot updates (4 broke CI) | ✅ #46 | 9 → 9.5 |

### Every bug found in round 3

| # | Area | Bug | Severity | Fixed in |
|---|------|-----|----------|----------|
| 1 | Security | Web cache poisoning: a request with a forged Host header was written to the static page cache with that host in every link, script and image URL (all later visitors got a broken page / links to the attacker's domain) | High | pr/39 |
| 2 | Build | Committed storefront CSS was older than the source (green buttons still #1f8a4c, 4.37:1 contrast) and nothing checked it | Medium | pr/39 |
| 3 | Tests | Trusted-host setting leaked between tests (static on Request) | Low | pr/39 |
| 4 | Storefront | After signing in, pages you'd seen as a guest could still show the guest version for up to 5 minutes (browser cached them with max-age=300) | Medium | pr/40 |
| 5 | Admin | Fulfilment and Support staff landed on the Dashboard they can't use: it stayed on "Loading…" forever | Medium | pr/41 |
| 6 | Admin | Opening a page your role can't use (by link/URL) showed an empty table and action buttons instead of "403" | Low | pr/41 |
| 7 | Admin / money | Finance (who approves transfers) could not open Payments at all: the list needed the "manage orders" permission | High | pr/41 |
| 8 | Money | Double-clicking "Send receipt" (or a second tab) created two receipts for the same order; both could be approved | High | pr/41 |
| 9 | Storefront | After the colour change, error messages (wrong password, checkout problems, failed receipt) showed in green, like success | Medium | pr/41 |
| 10 | Admin | Staff other than Super Admin couldn't read the feature switches (403 on every page), so switched-off sections still showed in their menu | Low | pr/41 |
| 11 | Storefront | Track order said "Pay" again while a receipt was being checked (customers could pay twice); the pay page still offered the receipt form | Medium | pr/41 |
| 12 | Storefront | Back button after placing an order showed the old cart on the checkout page; header cart count stale after back | Low | pr/41 |
| 13 | Security | Invisible control characters (null bytes) and right-to-left override characters were saved in names/addresses (can break exports and disguise text) | Low | pr/41 |
| 14 | Money | "Pay from wallet" for membership with too little in the wallet took the partial amount from the wallet and then showed an error (money parked in an unpaid purchase) | Medium | pr/41 |
| 15 | Robustness | Query strings with arrays (`/shop?sort[]=x`, `/pay/…?phone[]=1`, admin filters) crashed with 500 and filled the logs; admin lists accepted `per_page=99999999` | Medium | pr/42 |
| 16 | API | `GET /api/v1/membership` crashed (500) for a signed-in customer who never had a membership | Medium | pr/42 |
| 17 | Security | Admin sign-in accepted any `redirect=` value (defence in depth: only /admin paths now) | Low | pr/42 |
| 18 | Deploy | After an update, cached pages kept pointing to the old CSS/JS file names (deleted by the new build) for up to 15 minutes: unstyled pages. `php artisan optimize` now empties the page cache; deploy steps use it | Medium | pr/43 |
| 19 | Accessibility | Shop pagination used prohibited ARIA (Lighthouse a11y 96) | Low | pr/43 |
| 20 | Admin | (found in the final check) The new dashboard cache returned lists as broken objects on the second load ("top_products.map is not a function"); stored as plain arrays now, test uses the real cache store | High (caught before merge) | pr/43 |
| 21 | Accessibility | ~50 admin fields had no accessible name (placeholder-only filters, labels not linked to their inputs) | Low | pr/44 |
| 22 | Admin | Demo data page scrolled sideways on phones | Low | pr/44 |

### Speed (before → after, Docker + nginx, demo data)

| Page / action | Before | After |
|---|---|---|
| Lighthouse phone (slow 4G simulated): home / product / shop LCP | 1.21 / 1.95 / 1.36 s | **1.10 / 1.50 / 1.49 s** |
| Lighthouse phone: membership, login, signals LCP | 0.99–1.25 s | **0.94–0.96 s** |
| Lighthouse desktop LCP (all key pages) | 0.25–0.56 s | 0.27–0.47 s |
| Lighthouse scores (perf / a11y / best practices / SEO) | 97–100 / 95–96 / 100 / 100 | **100 / 96–100 / 100 / 100** |
| CLS | 0 | 0 |
| Page weight: product / shop / home | 264 / 472 / 194 KB | **170 / 337 / 148 KB** |
| Cached pages (nginx, no PHP) | 0.5–0.7 ms | 0.5–0.7 ms |
| Uncached pages p95 | 25–43 ms | 17–45 ms (budget 150 ms) |
| API p95 | 28–43 ms | 19–34 ms (budget 100 ms) |
| `/account` (member with a big team) | 90 queries / 124 ms | **58 queries / ~100 ms** |
| `/account/team` | 33 queries / 45 ms | **11 queries / 19 ms** |
| `/account/wallet` | 10 queries / 16 ms | **5 queries / 14 ms** |
| Admin dashboard | 54 queries / 70–78 ms | first load same, **then 6 queries / 8 ms** |
| Admin first load (JS, gzip) | 177 KB | 172 KB (98 lazy chunks, 438 KB total) |
| Storefront CSS / JS (gzip) | 10.1 / 4.3 KB | 8.9 / 4.4 KB |

Security changes were measured as they went in: the host check, the query normaliser and the stricter CSP added no measurable time.

### Not done, or only partly, and why
| Item | Why | Next step |
|---|---|---|
| Phone LCP ≤ 1.0 s under Lighthouse's simulated slow 4G on photo-led pages (home 1.10 s, product 1.50 s) | At 1.6 Mbps the photo download alone takes most of that time; text pages are already < 1 s. On real 4G the pages paint well under 1 s | Smaller hero crops, or a CDN with image resizing near Ethiopia |
| Critical CSS inlined | The whole CSS is 8.9 KB and Lighthouse estimates 0 ms to gain; inlining would also need a per-build CSP hash | Revisit if the CSS grows |
| Brotli on the Docker nginx | The official nginx image has no Brotli module; gzip is on. `.htaccess` turns Brotli on where the host has it | Use a host/CDN with Brotli |
| Admin CSP still allows inline styles | The admin UI library injects styles at runtime | Storefront is strict (attributes only) |
| Virtualised admin tables | Every list is paged on the server (25–50 rows), so there is nothing long to virtualise | — |
| Optimistic UI in the admin | Money actions must wait for the server; lists refresh in ~10–20 ms anyway | Could add for non-money toggles |
| Larastan level 7+ | 108 findings at level 7, mostly union-type strictness, none found to be bugs | Raise gradually |
| Lighthouse budgets in CI | CI serves PHP directly (no gzip, no page cache), so its phone numbers are slower (shop 2.3 s, product 2.0–2.3 s); budget 2.5 s on phones, 1.0 s on desktop | Run CI behind nginx to use the real numbers |

---

# Round 1 review (for reference)

_Review of the whole app before the first merge (15 Nov 2026). Branches
`pr/21` … `pr/28` on top of the original stack (#1–#20)._

## How it was checked
- **Decisions and brief:** every row of `QUESTIONS.md` and every line of the
  original brief, compared with the code.
- **Logic:** read every money path (pricing, membership, referral, ledger,
  payments, cash-out, signals, feature switches), then wrote tests that try to
  break them: wrong input, double clicks, replays, races, odd partial refunds.
- **Access:** an automatic test reads the real route list and calls every
  route as guest, customer, member, expired member, each staff role and Super
  Admin, plus "someone else's data" (IDOR) tests.
- **Screens:** every storefront and admin page in headless Chrome, with
  realistic data, on phone and desktop, light and dark.
- **Tools:** Larastan level 5, gitleaks over the full git history (52 commits,
  every branch), `composer audit`, `npm audit`, Lighthouse before/after, and a
  fresh install from zero in Docker.

Result: **303 tests** (2,548 assertions) pass on SQLite
and MariaDB; Larastan 0 errors; gitleaks no real secrets; both audits clean.

## Scores

| Area | Before → after (/10) | Problems found | Fixed? |
|---|---|---|---|
| Decisions vs brief | 8 → 9.5 | Doc drift (admin build path); a "Product reviews" switch for a feature never built; the 5-character admin password conflicted with the new strong-password request | ✅ |
| Money logic & ledger | 8.5 → 9.5 | Two checkouts could both take a coupon's last use; Chapa's verify answer wasn't checked to be for the *same* transaction; "Pay with Chapa" twice opened two payable checkouts | ✅ |
| Feature switches | 7.5 → 9.5 | Shop off still showed the pay/track pages and payment API; jobs kept running while their feature was off (membership off would have expired members and removed them from Telegram) | ✅ |
| Accounts & sign-in | 6 → 9.5 | No common-password list, short minimums, no account pause, no customer 2FA, no "Was this you?" notices, email changed without confirmation, no admin idle timeout, no "sign out everywhere", API tokens never expired | ✅ |
| Money safety (cash-out) | 6 → 9.5 | Cash-out account typed fresh on every request: someone in an account could cash out to their own number at once; no daily limits; no idempotency on cash-out requests | ✅ |
| Referral abuse | 7 → 9 | Only self-referral was checked; no fake-account patterns; sign-up limit 10/min per IP | ✅ |
| Access control | 7.5 → 9.5 | Fulfilment and Support staff could approve bank transfers (which gives value) | ✅ |
| Web security | 7 → 9.5 | **Rate limits shared one counter across routes**; cached pages (served without PHP) had **no security headers and no CSP header**; CORS allowed any origin; no `security.txt`; no `no-store` on private pages | ✅ |
| Uploads | 6.5 → 9.5 | Payment proofs stored as uploaded (phone photos carry GPS; files could carry payloads); private disk had a public serve route | ✅ |
| Secrets & logs | 6 → 9 | Logs could contain keys/tokens; no secret scanning; no owner alerts; no log retention | ✅ |
| Backups | 7 → 9 | No restore test | ✅ |
| Dependencies & CI | 6.5 → 9.5 | No Larastan, no audits, no Dependabot, 9 moderate npm advisories (dev tooling) | ✅ |
| Server & local setup | 6 → 9 | Docker files owned by root (permission errors on WSL/Linux); port clash with your other project; `km:doctor` only warned; images not pinned; no text compression | ✅ |
| Screens & wording | 7.5 → 9 | Invisible "Made to order" badge in dark mode; plain Laravel error pages; template logo in the admin; raw JSON in the fraud queue; admin member page overflowed on phones; status said "Failed" for "not set" | ✅ |
| Privacy | 9 → 9.5 | (Already good.) Kept: guests get no session and no IP stored; new fraud checks keep networks only as a hashed key in the cache for 24 h | ✅ |
| Speed | 9.5 → 9.5 | No gzip. Security changes measured: no slowdown (cached pages < 1 ms, uncached ~30 ms, LCP on phone 1.2–1.4 s, admin bundle unchanged) | ✅ |
| Accessibility | 9 → 9.5 | Missing labels on two fields, heading order on /shop | ✅ |
| Code quality | 7 → 9 | 25 Larastan findings (wrong nullable types; none in money code) | ✅ |

## What's in place now (short)
- **Accounts** ([docs/account-security.md](docs/account-security.md)):
  - Passwords: customers ≥ 10 and staff ≥ 12 characters, with a 10k
    common-password list and a breach check.
  - Sign-in pauses for 15 min after 10 wrong passwords, on every channel.
  - 2FA is required for staff and optional for customers.
  - "Was this you?" notices for new sign-ins, password, email, payout-account
    and 2FA changes.
  - Email changes only after the link sent to the new address is opened.
  - Admin is signed out after 30 min idle and after 12 h at most. App tokens
    last 30 days.
  - "Sign out all devices" is available on the website, in the app and in the
    admin.
- **Money** ([docs/wallet-cashout-fraud.md](docs/wallet-cashout-fraud.md)):
  - Every change goes through the double-entry ledger, in a transaction with
    row locks and idempotency keys.
  - Amounts are always calculated on the server.
  - Transfers are approved by staff with the `approve-payments` ability;
    approving twice gives value once.
  - Cash-out account: a 48 h wait after any change, plus an alert. The first
    payout needs Super Admin to verify the account.
  - Daily limits, and idempotent requests.
  - Fraud holds on self-referral, shared accounts, fake-account patterns and
    a member's first cash-out from a new account.
- **Web** ([docs/web-security.md](docs/web-security.md)):
  - Strict CSP and HSTS, sent by PHP **and** by nginx/Apache for cached pages.
  - Cookies: HttpOnly, SameSite, Secure on https.
  - CSRF on every form (tested route by route). CORS limited to our own site.
  - Rate limits per route. `security.txt`.
- **Uploads:**
  - Payment proofs are re-drawn as JPEG (no hidden data) or must be real PDFs.
  - They're kept private and served inert.
- **Secrets:**
  - Only in `.env`, and scrubbed from logs.
  - Gitleaks runs in CI over the whole history.
- **Logs and alerts:**
  - `security.*` events are kept: routine noise for 13 months, audit records
    forever.
  - Owner Telegram alerts for admin sign-ins from a new browser, admin pauses
    and 2FA resets, manual ledger entries, fraud flags and repeated bad webhook
    signatures.
- **Ops** ([docs/deploy.md](docs/deploy.md)):
  - `km:doctor` fails when anything is unsafe.
  - `km:backup-verify` is the restore test.
  - Optional admin IP lock. Production checklist.
  - Dependabot, pinned images.

## Remaining risks (not fixed, and why)
| Risk | Why it's still open | What to do |
|---|---|---|
| Telegram and SMS tested only with fakes | No accounts/keys yet | Run one bot join and one SMS when keys exist (`km:doctor` shows what's missing) |
| No SMS provider | Your choice (QUESTIONS A) | Until then security notices go by email and Telegram only |
| Backups stay on the same server until copied | Needs a storage account (e.g. Backblaze/S3) | Copy daily off the server; run `km:backup-verify` monthly |
| No WAF / DDoS shield | Hosting decision | Put the site behind a CDN/WAF with tracking features off |
| Data location (Proclamation 1321/2024) | Hosting decision (QUESTIONS B) | Choose hosting with that in mind |
| Super Admin and the database owner can do anything | One-person team by design | Keep 2FA on, use `ADMIN_ALLOWED_IPS`, copy logs/backups off the server |
| 2FA optional for customers | You asked for optional | The Security page nudges members with earnings to turn it on |
| Recovery codes are stored encrypted, not hashed | Low risk (encrypted with the app key) | Could be hashed later |
| CSP allows inline *styles* in the admin | Needed by the admin UI library | Storefront: no inline `<style>` at all, only style attributes (round 3) |
| Larastan at level 6, not max | Level 7+ mostly flags union-type strictness | Raise gradually |
| No external penetration test | Out of scope here | Recommended before a big launch |

## How to check it yourself
```bash
php artisan test                 # 358 tests
php artisan km:doctor            # red = unsafe, yellow = setup to do
php artisan km:backup && php artisan km:backup-verify
```
CI (on the top PR) runs Pint, Larastan (level 6), the tests on MySQL 8, the admin
build, a check that the committed builds match the sources, server-time budgets,
Lighthouse on desktop (≥ 95, LCP ≤ 1.0 s) and phone (LCP ≤ 2.5 s on slow 4G in CI, which serves PHP without gzip or page cache),
gitleaks over the whole history and the dependency audits.

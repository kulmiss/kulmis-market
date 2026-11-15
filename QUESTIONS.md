# Questions and decisions

Each question has a recommendation, and I build on it until you say
otherwise. On the night of 2026-11-14 you asked me to take my recommendation
everywhere, so every **Decided (recommended)** row below is my call. You can
change any of them.

| # | Question | Decision | Status |
|---|----------|----------|--------|
| 1 | Shop name | **Kulmis Market** | ✅ Answered by you |
| 2 | Base for the admin | satnaing/shadcn-admin + shadcn/ui, no saas-kit | ✅ Answered by you |
| 3 | Laravel version | **Laravel 13** (13.42). Laravel 11 had 5 unpatched security advisories. Needs PHP 8.3+. | ✅ Answered by you |
| 4 | SMS provider for Ethiopia | One `SmsGateway` interface with a `log` driver (default) and a generic HTTP JSON driver (`SMS_DRIVER=http`, `SMS_URL`, `SMS_TOKEN`). If your provider's API has a different shape, it's one small class to adapt. | Decided (recommended), you pick the provider |
| 5 | Domain | Placeholder `kulmis.market`. Change `APP_URL` and `MAIL_FROM_ADDRESS`. | Decided (recommended) |
| 6 | Static analysis (Larastan) | Larastan level 5 runs in CI (added in the review, PR 21). 0 errors. | Decided (recommended) |
| 7 | Repo layout | Laravel at the root, React admin in `admin/` building to `public/admin-app` (served at `/admin`). Works on shared hosting. | Decided (recommended) |
| 8 | Rounding | Customer discounts round half-up to the santim. Commissions and rewards round down. | Decided (recommended) |
| 9 | Members-only product: who can see it? | Everyone sees it with a "Members only" badge (good for "Join & save"). Only active members can buy it. | Decided (recommended) |
| 10 | Do members keep benefits during the 3-day grace period? | Yes. Discounts, signals and earnings continue until the grace period ends. | Decided (recommended) |
| 11 | Free shipping "over 3,000 ETB": is exactly 3,000 included? | Yes (≥ 3,000), measured on goods after member discount and coupon, excluding shipping. | Decided (recommended) |
| 12 | Can coupons stack with the member discount? | Yes. The coupon applies after the member discount. Use `members_only` or a lower value to control it. | Decided (recommended) |
| 13 | Someone signed up without a code: can a later code set their referrer? | No. The referrer is fixed at sign-up (none in this case). Only Super Admin can set or change it. | Decided (recommended) |
| 14 | Refund after earnings are already *available* | They're reversed from *available*. If the member already spent or withdrew them, the wallet goes negative and cash-out stays blocked until it's positive again. | Decided (recommended) |
| 15 | Guest buyers (no account) | The referrer is saved per order, and on the signals subscription at the first purchase (kept for renewals). | Decided (recommended) |
| 16 | Does a skipped level get passed up to the next active member? | No (as in your brief). The skipped share stays with the shop, and each level only ever gets its own rate. | ✅ From brief |
| 17 | Does an amount paid from the wallet count as "actually paid" for commissions? | Yes. Wallet money is real earnings, and the sale is the same sale. | Decided (recommended) |
| 18 | Can an expired member cash out their balance? | Yes. The wallet is kept when membership expires (per brief), and cash-out stays open. Earning stops. | Decided (recommended) |
| 19 | Daily sign-up limit exceeded | The new people are still linked (they may be real), but the referrer is flagged and held until you review. | Decided (recommended) |
| 20 | "Device" for self-referral checks | Uses a hash of the essential cart token. No fingerprinting and no extra cookie. | Decided (recommended) |
| 21 | Ship the admin build in git? | Yes. `public/admin-app` is committed, so shared hosting needs no Node. CI rebuilds it to make sure it compiles. | Decided (recommended) |
| 22 | Guests and sessions | Public pages never start a session for guests, so the only cookies are `km_cart` and `km_ref`. Guest checkout uses the stateless API. Signed-in customers get a normal session. | Decided (recommended) |
| 23 | Storefront look | Clay, sand and coffee colours, serif headings with the system font stack (no web fonts, for speed and privacy). Change the colours in `resources/css/app.css` (`@theme`). | Decided (recommended), easy to change |
| 24 | Security headers | Strict CSP (one hashed inline script, nothing third-party), plus a meta CSP so cached pages keep it. `form-action` allows `checkout.chapa.co` for the payment redirect. | Decided (recommended) |
| 25 | Backups | Nightly encrypted backup (libsodium) of the DB, proofs and uploads, kept 14 days on the server. **You still need to copy them off the server.** | Decided (recommended) |
| 26 | Database name | `kulmis-market` (you asked, 15 Nov). | Decided (you) |
| 27 | Admin password length | Minimum 5 characters (you asked, 15 Nov), set by `ADMIN_PASSWORD_MIN` in `.env`. 2FA is still required for every admin. Note: the ASVS L2 level in your brief asks for 12. | Decided (you) |
| 28 | Product reviews switch | Removed. Reviews were never built (the brief said "if added"), so the switch did nothing and only confused the overview. | Decided (review) |
| 29 | What happens to background jobs when a feature is off? | They pause. Membership off: nothing expires, no reminders, members stay in the signals channel. Telegram bot off: nobody is marked removed (they couldn't actually be removed). Turning it back on catches up. | Decided (review) |
| 30 | Paying an order while the shop is switched off | Not possible: the pay/track pages and payment API are hidden too. Unpaid orders are auto-cancelled as usual. | Decided (review) |

## Still open: needs you
| # | What | Why |
|---|------|-----|
| A | **SMS provider**: which one, plus its API details | Until then SMS only go to the log. Email and Telegram work. |
| B | **Domain + hosting** | Set `APP_URL`, `MAIL_FROM_ADDRESS`. Note: Proclamation 1321/2024 has rules on keeping personal data on servers in Ethiopia, so this may matter when you choose hosting. |
| C | **Chapa account** (test, then live keys + webhook secret) | Card / Telebirr payments. |
| D | **Telegram bot + private channel** | Signals access. |
| E | **Look & feel** | The colours, logo and texts are placeholders that fit "warm, handcrafted". Tell me what to change. |

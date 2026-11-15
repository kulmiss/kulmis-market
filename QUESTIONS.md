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
| 23 | Storefront look | **White and green** (same green as the logo and the member area), a touch of gold, serif headings with the system font stack (no web fonts, for speed and privacy). Change the colours in `resources/css/app.css` (`@theme`: `brand` = green, `mist` = white/light green, `ink` = text). | Decided (you, round 3) |
| 24 | Security headers | Strict CSP (one hashed inline script, nothing third-party), plus a meta CSP so cached pages keep it. `form-action` is `'self'` only (no payment gateway to redirect to). | Decided (recommended) |
| 25 | Backups | Nightly encrypted backup (libsodium) of the DB, proofs and uploads, kept 14 days on the server. **You still need to copy them off the server.** | Decided (recommended) |
| 26 | Database name | `kulmis-market` (you asked, 15 Nov). | Decided (you) |
| 27 | Password length | Staff ≥ 12, customers ≥ 10, plus a common-password list (your security review asked for strong rules, which replaces the 5-character minimum from earlier). Set by `ADMIN_PASSWORD_MIN` / `CUSTOMER_PASSWORD_MIN`; `km:doctor` turns red if staff is below 12. | Decided (review) |
| 28 | Product reviews switch | Removed. Reviews were never built (the brief said "if added"), so the switch did nothing and only confused the overview. | Decided (review) |
| 29 | What happens to background jobs when a feature is off? | They pause. Membership off: nothing expires, no reminders, members stay in the signals channel. Telegram bot off: nobody is marked removed (they couldn't actually be removed). Turning it back on catches up. | Decided (review) |
| 30 | Paying an order while the shop is switched off | Not possible: the pay/track pages and payment API are hidden too. Unpaid orders are auto-cancelled as usual. | Decided (review) |
| 31 | Customer 2FA | Optional, offered on Account → Security with a nudge when they have wallet earnings. Required for staff. | Decided (review) |
| 32 | "New sign-in" detection without tracking | A random cookie set only after sign-in (hash stored), not IP or fingerprinting, so guests stay untracked. | Decided (review) |
| 33 | Security notices vs notification switches | Security notices always go out (email/SMS/Telegram), even when order notifications are switched off. | Decided (review) |
| 34 | How is a payout account "verified"? | Super Admin confirms the name shown by Telebirr/the bank on the first payout ("I checked the account"). No external API is needed. | Decided (review) |
| 35 | Daily cash-out limits | 2 requests and 50,000 ETB per member per 24 h (editable). | Decided (review) |
| 36 | First cash-out of a new member | Held for review if they joined less than 30 days ago (editable). | Decided (review) |
| 37 | Fake-account detection without storing IPs | The network is kept only as a keyed hash in the cache for 24 h. | Decided (review) |
| 38 | Who can approve a bank/Telebirr transfer? | Finance, Manager and Super Admin (new `approve-payments` ability). Fulfilment and Support can see payments but not approve them, because approving gives value. | Decided (review) |
| 39 | Local Docker port | 8090 by default (8080 is used by your other project). | Decided (you) |
| 40 | Admin IP lock | Optional `ADMIN_ALLOWED_IPS`. Off by default because your IP may change; 2FA is always required. | Decided (review) |
| 41 | `km:doctor` | Fails (exit 1) when anything is unsafe, so deploy scripts can stop. Missing setup (SMS, mail…) is a warning only. | Decided (review) |
| 42 | Sign-up in the referrer's own browser | Linked (team is right) + flag: the referrer's earnings wait for review. Before, the link was silently refused, which looked like "referral not working". | Decided (bug #7) |
| 43 | Many sign-ups from one network | Review-only (soft) flag at 10 in 24 h; no hold, because mobile carriers share IPs. Local/private IPs are ignored. | Decided (bug #7) |
| 44 | Currency change and existing amounts | Nothing is converted: amounts keep their numbers, only the symbol/format changes. Admin must confirm. | Decided (you) |
| 45 | ~~"Block checkout" when Chapa can't take the currency~~ | No longer applies: Chapa was removed (#56). Transfers work in any currency. | Obsolete |
| 46 | Currency decimals | Choice of "only when needed" (default, as today), never, or always 2. Amounts are still stored to 1/100. | Decided (round 2) |
| 47 | "Team size" badges | Count **active** members you invited yourself (as before), so fake sign-ups don't earn badges. "Total team" counts active members in all 3 levels. | Decided (round 2) |
| 48 | Badge messages | In-app notification + celebration always. SMS/email/Telegram for badges are off by default (`badges.messages`) because SMS costs money. | Decided (round 2) |
| 49 | Top referrer badge | Awarded on the 1st of each month to last month's #1 (threshold = rank, editable). | Decided (round 2) |
| 50 | "Highest badge" | The unlocked badge with the highest "importance" number (editable per badge). Diamond is highest by default. | Decided (round 2) |
| 51 | Demo data and money totals | The admin dashboard shows demo OR real numbers (a switch), never both added together. The ledger page shows real balances, with demo balances apart. | Decided (round 2) |
| 52 | Demo photos | Real free photos from Pexels (no attribution needed; credited in CREDITS.md). They are craft photos that fit each product, not photos of your own products. | Decided (round 2) |
| 53 | Demo contacts | Demo phones use +251 900 1… and emails end in .test; messages to them are never sent, so a staging server with a real SMS provider can't text strangers. | Decided (round 2) |
| 54 | Member area look | Its own green & white layout (sidebar / bottom bar), accent editable in Settings → Appearance. The Super Admin dashboard is unchanged. | Decided (you) |
| 55 | "Newest in your team" privacy | Level 1 by first name + initial; levels 2 and 3 show as "New member" (as before: counts only for levels 2–3). | Decided (round 2) |
| 56 | Payment methods | **No Chapa / no online gateway.** Orders, membership and guest signals are paid by Telebirr or bank transfer with a receipt that staff approve, and members can use their wallet (all or part). Refunds of transfers are sent back by hand and recorded; wallet payments go back to the wallet. Existing page texts that mentioned Chapa are updated by a migration (only sentences you never edited). | Decided (you) |
| 57 | Logo colour | Green `#1f8a4c` (the member-area accent default) with the gold knob and sand basket, everywhere the logo appears. The storefront is white and green too (#23). If you change the member accent in Settings, the logo stays this green (it is also in the favicon and app icons). | Decided (you) |
| 58 | Religious images | None. Demo photos and products avoid religious symbols and festivals (the cross pendant and Meskel print were replaced by a quartz bracelet and a macramé wall hanging; a photo with evil-eye beads was cropped). Keep this in mind for your own product photos. | Decided (you) |
| 59 | PHP version | **PHP 8.3** on the live server, in Docker and in CI, because almost every host offers it. phpunit 13 and the PHP 8.5 Docker image need PHP 8.4+, so Dependabot skips them for now (`.github/dependabot.yml`). TypeScript 7 is skipped too until typescript-eslint supports it. To move to PHP 8.4 later: change `composer.json`, the Dockerfile and CI together, then remove those ignore rules. | Decided (you) |
| 60 | Light/dark switch off | When the Super Admin turns it off, the shop and member area are always light (device dark mode is ignored too) and the button is hidden. The admin panel keeps its own light/dark setting for staff. | Decided (you + recommended) |
| 61 | Header account button | Far right. Guests: person icon → Sign in. Signed in: initial + first name (name hidden on small phones, initial only) → My account. | Decided (you) |
| 62 | Who sees Payments in the admin | Anyone who handles orders, approves transfers (Finance) or does refunds. Only Finance/Manager/Super Admin can approve. | Decided (bug fix, round 3) |
| 63 | A second receipt while one is being checked | Refused with "We already have your receipt and are checking it"; after a rejection the customer can send a new one. | Decided (recommended) |
| 64 | Service worker | On: faster repeat visits and an offline page. It never stores account, admin, cart, checkout, pay or API pages. Switch it off with **Feature switches → Offline page**: phones then remove the saved pages on their next visit. | Decided (recommended) |
| 65 | Phone speed target | Text pages are under 1.0 s LCP on Lighthouse's slow-4G phone test; photo pages are 1.1–1.5 s because of the photo download. CI fails above 2.5 s on phones (CI has no gzip/page cache) and 1.0 s on desktop. | Decided (measured) |
| 66 | Customer two-step sign-in switched off | No new set-ups. People who already use it keep being asked for the code and can still turn it off themselves (turning it off for them would weaken their account without telling them). Staff two-step sign-in is not affected. | Decided (recommended) |
| 67 | Message switches | Each customer message type has a switch (Feature switches → Messages). Account-safety messages (new sign-in, password changed, …), password-reset codes and email-confirm links are always sent. | Decided (recommended) |
| 68 | Badge switches | Each badge has a switch; it is the same as "Active" on the Badges page. Off: it is hidden and nobody unlocks it. Members who had it keep the record and see it again when it is back on. | Decided (recommended) |
| 69 | Badge bonus: grace days | No bonus that month: every day must be paid. A month counts from when it was paid. | Decided (you) |
| 70 | Badge bonus: which badges pay monthly | Only Bronze–Diamond (active level-1 members). Whole-team badges (25/100/250) and all other badges have a one-time bonus field, default 0. | Decided (you) |
| 71 | Badge bonus: first payout | The job on the 1st waits until you check the list and press "Pay now" once; then it is automatic. | Decided (you) |
| 72 | Badge bonus: free months given by staff | They don't count: not for the member's own "paid every day", not as an active team member, not as team money. Bonuses come only from real money. | Decided (recommended) |
| 73 | Badge bonus: refunds later | A refund before the bonus is ready lowers or cancels it. After it is ready it is not taken back (the 5% budget and 2% team limit already cover this). | Decided (recommended) |
| 74 | Profit guard | Settings that could pay out more than 35% of a membership (team earnings + badge bonus + loyalty) can't be saved. Today: 20.34%, you keep at least 3,983 of every 5,000 ETB. | Decided (you + recommended) |
| 75 | Product grids on phones | Info cards are one column on phones; product photos stay two per row on phones (normal for shops, easier to browse). Say if you want one. | Decided (recommended) |
| 76 | Money history codes | Admin shows easy names first and the technical type small underneath; CSV files keep the codes so spreadsheets and the accountant can match them. | Decided (recommended) |
| 77 | Member money history | The wallet page lists every wallet line, including "on the way → ready to use" moves (two lines each), so the totals always add up. | Decided (recommended) |

## Still open: needs you
| # | What | Why |
|---|------|-----|
| A | **SMS provider**: which one, plus its API details | Until then SMS only go to the log. Email and Telegram work. |
| B | **Domain + hosting** | Set `APP_URL`, `MAIL_FROM_ADDRESS`. Note: Proclamation 1321/2024 has rules on keeping personal data on servers in Ethiopia, so this may matter when you choose hosting. |
| D | **Telegram bot + private channel** | Signals access. |
| E | **Look & feel** | Logo A (smiling mesob) is in, in the member-area green. The shop is white and green; tell me what to change. |

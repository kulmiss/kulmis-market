# Kulmis Market — security and quality review

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
  - Chapa webhook: signature, then re-verified for amount, currency and the
    same reference. Replays give nothing.
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
| Chapa, Telegram and SMS tested only with fakes | No accounts/keys yet | Run one test-mode payment, one bot join and one SMS when keys exist (`km:doctor` shows what's missing) |
| No SMS provider | Your choice (QUESTIONS A) | Until then security notices go by email and Telegram only |
| Backups stay on the same server until copied | Needs a storage account (e.g. Backblaze/S3) | Copy daily off the server; run `km:backup-verify` monthly |
| No WAF / DDoS shield | Hosting decision | Put the site behind a CDN/WAF with tracking features off |
| Data location (Proclamation 1321/2024) | Hosting decision (QUESTIONS B) | Choose hosting with that in mind |
| Super Admin and the database owner can do anything | One-person team by design | Keep 2FA on, use `ADMIN_ALLOWED_IPS`, copy logs/backups off the server |
| 2FA optional for customers | You asked for optional | The Security page nudges members with earnings to turn it on |
| Recovery codes are stored encrypted, not hashed | Low risk (encrypted with the app key) | Could be hashed later |
| CSP allows inline *styles* | Needed by the admin UI library | Scripts stay strict (one hashed inline script) |
| Larastan at level 5, not max | Higher levels mostly flag style, not bugs | Raise gradually |
| No external penetration test | Out of scope here | Recommended before a big launch |

## How to check it yourself
```bash
php artisan test                 # 303 tests
php artisan km:doctor            # red = unsafe, yellow = setup to do
php artisan km:backup && php artisan km:backup-verify
```
CI (on the top PR) runs Pint, Larastan, the tests on MySQL 8, the admin build,
Lighthouse ≥ 95 + LCP ≤ 1.5 s, gitleaks over the whole history and the
dependency audits.

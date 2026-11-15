# Web security, uploads, secrets and logs

| Area | What's in place |
|---|---|
| Headers | Strict CSP (one hashed inline script, nothing third-party, `frame-ancestors 'none'`, `upgrade-insecure-requests` on https), HSTS on https, `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy, Permissions-Policy, COOP/CORP, `X-XSS-Protection: 0`, no `X-Powered-By`/server version. **nginx and `.htaccess` send them too**, so cached pages served without PHP have them. Account, admin and wallet pages are `no-store`. |
| Cookies | `km_session`: HttpOnly, SameSite=Lax, Secure automatically when `APP_URL` is https, encrypted. Guests get only `km_cart` and `km_ref` (and nothing on most pages). `km_device` only after sign-in. |
| CSRF | Every website form and the admin API (session) need the CSRF token. The app API uses bearer tokens (no cookies). Tested route by route. |
| CORS | Only `APP_URL` (+ `CORS_ALLOWED_ORIGINS`), no credentials. |
| security.txt | `/.well-known/security.txt` (contact = support email, expires in a year). |
| Rate limits | Per route **and** per visitor (Laravel's numeric limits shared one counter across routes; fixed by `ThrottlePerRoute`). Login 5/min per account+IP and 20/min per IP; 10 wrong passwords pause the account; sign-up 5/h + 20/day per network; checkout, tracking, coupons 10/min; cart 60/min; cash-out 5/min; payout account 5/10 min; password reset 3/10 min; API 120/min. |
| Uploads | Product photos: re-encoded to AVIF/WebP/JPEG (max 8000 px), originals not kept. Payment proofs: photos re-drawn as JPEG (drops EXIF/GPS and anything hidden), PDFs must start with `%PDF-`, 5 MB max, random names, private disk `storage/app/private` (no public URL). Admins view them through an authorised endpoint that sends `nosniff`, a sandbox CSP and `no-store`. |
| Secrets | Only in `.env` (Telegram, SMS, mail, backup key). None in the database or settings (tested). Logs are scrubbed of keys, tokens, passwords, codes and card-like numbers. Gitleaks scans the whole git history in CI. |
| Webhooks | Telegram: secret header, each `update_id` handled once. 5+ bad signatures/hour alert the owner. |
| Security log | `security.*` events in the activity log (sign-ins, failures, pauses, 2FA, email/password/payout changes, cash-outs, admin actions, referrer changes). Routine sign-in noise is removed after 13 months (`km:prune-logs`, weekly); money, admin and account-change records are kept. |
| Owner alerts (Telegram) | Set `TELEGRAM_ADMIN_CHAT_ID`. Sent for: admin sign-in from a new browser, admin account paused, admin 2FA reset, manual ledger entries, new fraud flags, repeated bad webhook signatures. |
| Dependencies | `composer audit` and `npm audit` in CI (both clean). |

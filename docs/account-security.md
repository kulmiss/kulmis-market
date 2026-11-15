# Account security

Code: `app/Security/*`, settings in `config/security.php`, texts in Admin → Settings → Messages.

| Rule | How it works |
|---|---|
| Passwords | Customers ≥ 10 characters, staff ≥ 12 (`CUSTOMER_PASSWORD_MIN`, `ADMIN_PASSWORD_MIN`). Refused: the 10,000 most common passwords (+ local ones like `ethiopia123`), common word + digits (`Password2024!`), numbers only under 16 digits, one repeated character, and anything built from the person's phone, email or name. Optional breach check (`PASSWORD_PWNED_CHECK`, on in production; k-anonymity, skipped if offline). No "must contain a symbol" rules. |
| Sign-in pause | 10 wrong passwords in a row → that account pauses 15 minutes on the website, app **and** admin, and the owner gets "too many attempts". Per-IP limits (5/min per login, 20/min per IP) still apply on top. |
| Two-step sign-in | Required for all staff. Optional for customers (Account → Security, or the app API). Authenticator app (TOTP), 8 one-time recovery codes, a code can't be reused. Turning it off needs the password **and** a code. |
| "Was this you?" | Sent by every channel the person has (email, SMS, Telegram) — even if marketing notifications are switched off — for: sign-in from a new browser, new app sign-in, password changed, email change requested (to the **old** address), email changed (both addresses), two-step on/off, sign-in paused, signed out everywhere. Payout-account changes are added in the money-safety PR. |
| New browser | A random `km_device` cookie (set only after sign-in; only its hash is stored, with a "Chrome on Android" label). No IP, no fingerprinting. The first sign-in right after sign-up doesn't alert. |
| Email change | Needs the current password. A link goes to the **new** address and works 24 h, once. The email only changes when it's opened; the old address is told right away. |
| Session timeout | Admin: 30 min idle, 12 h maximum. App tokens: 30 days. Website: 2 h idle (or "keep me signed in"). |
| Sign out all devices | Account → Security (website), `POST /api/v1/me/sign-out-all` (app), "Sign out other devices" in the admin menu. Ends website sessions, app tokens and remember-me. A password change or reset does the same automatically. |
| Log | Every event is written to the activity log as `security.*` (sign-ins, failures, pauses, 2FA, email/password changes). |

Tests: `tests/Feature/Security/AccountSecurityTest.php`.

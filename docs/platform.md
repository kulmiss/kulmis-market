# Platform core

## Feature switches
- Defaults: `config/features.php` (`key => [label, group, default, parent]`).
- Overrides are saved in `feature_flags`. A feature is **effectively on** only
  when it and all its parents are on, so turning off `membership` also turns
  off referrals, the member discount, free shipping and early access.
- Code: `Features::enabled('signals')`, Blade `@feature('signals') … @endfeature`,
  routes `->middleware('feature:signals')`. A disabled feature answers 404.
  Nothing is deleted.
- Admin API: `GET/PUT /api/v1/admin/features` (the on/off overview page).

## Settings
- Defaults and types: `config/settings.php`. Types: `money` (integer santim),
  `bps` (basis points, 1500 = 15%), `ratio` (×100), `int`, `bool`, `string`, `text`.
- `Settings::get('membership.price')` returns `500000` (5,000 ETB).
- Admin API: `GET/PUT /api/v1/admin/settings`. A bulk update saves all
  values or none, and can carry a `reason` that goes into the activity log.

## Roles
`config/permissions.php` defines Super Admin (everything) plus Manager,
Fulfilment, Support and Finance, ready for future staff. Routes check
abilities with `->can('manage-orders')`.

| Role | Can |
|---|---|
| Super Admin | Everything, incl. feature switches, settings, activity log, changing a referrer |
| Manager | Products, orders, payments (incl. approving transfers), members, signals, content, reports |
| Fulfilment | Orders and shipping; sees payments but can't approve transfers |
| Support | Orders and members; sees payments but can't approve transfers |
| Finance | Ledger, refunds, approving transfers, cash-outs, fraud queue, reports |

Changing a member's referrer is Super Admin only, even if a role is given
`manage-referrals`. `tests/Feature/Security/AccessControlTest.php` checks
every route against guest, customer, member, expired member, each staff
role and Super Admin, plus the "someone else's data" (IDOR) cases.

## Admin auth and 2FA
- The admin API lives at `/api/v1/admin/*` and uses a same-domain session
  with the CSRF cookie. Personal API tokens can't reach it.
- Login is `POST auth/login` → `{two_factor:true}` → `POST auth/two-factor {code}`
  (or `{recovery_code}`).
- With `ADMIN_REQUIRE_2FA=true` (the default), an admin without 2FA can only
  call `two-factor/setup` and then `two-factor/confirm`, which returns 8
  recovery codes.
- TOTP follows RFC 6238 and refuses a code that was already used (replay
  protection). Secrets are stored encrypted.
- Create the first admin with `php artisan km:create-admin you@example.com`.

## Activity log
`Activity::log($action, $subject, $props, $reason)` writes to an append-only
table: updates and deletes throw. The IP is recorded only for signed-in users.

## Privacy
Sessions use the `privacy-database` driver, which stores no IP address or
user agent for guests.

## What "off" means for background jobs
Turning a feature off hides its pages, API and admin screens, and **pauses**
its jobs. Nothing is deleted:
- `membership` off → no expiries, no reminders, members keep signals access.
- `telegram_bot` off → the signals sweep waits (nobody can be removed from the
  channel without the bot, so nobody is marked removed either).
- `loyalty_reward` off → no rewards are granted.
- `notifications.*` off → that channel is skipped.

`tests/Feature/Platform/FeatureSwitchMatrixTest.php` checks every switch
against every route it guards.

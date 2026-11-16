# Running Kulmis Market locally

## With Docker (recommended)
On Windows, keep the project **inside WSL** (Ubuntu: `cd ~ && git clone …`),
not on the C: drive — it's many times faster. Run every command below in
the Ubuntu terminal.
```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan db:seed --class=DemoSeeder   # demo data for every section (about 30 s)
docker compose exec app php artisan storage:link
docker compose exec app php artisan km:create-admin you@example.com
```
- Shop: http://localhost:8090
- Admin: http://localhost:8090/admin (sign in → set up 2FA with your phone app)
- Mail inbox (Mailpit): http://localhost:8025
- MySQL from your computer: `127.0.0.1:3307` (database `kulmis-market`, user `kulmis`, password `secret`)
- Passwords: staff ≥ 12 characters, customers ≥ 10, and common passwords are refused.
- PHP runs as your own user id (`UID`/`GID`, default 1000), so files it creates belong to you. If your id isn't 1000 (`id -u`), set `UID=` and `GID=` in `.env` before `docker compose up -d --build`.

**"ports are not available"** when starting: another program on your computer
uses that port. Pick a free one in `.env` (`APP_PORT`, `MAILPIT_PORT` or
`DB_FORWARD_PORT`) and run `docker compose up -d` again. If you change
`APP_PORT`, also change `APP_URL` and `SANCTUM_STATEFUL_DOMAINS` to match.
The ports only listen on your own computer (127.0.0.1), not your network.

The `queue` and `scheduler` containers run jobs and cron. SMS go to the log
(`storage/logs`) until you set an SMS provider.

## Demo data
`php artisan db:seed --class=DemoSeeder` fills every section with six months
of made-up shop life: 37 products with real photos (7 categories, variants,
low stock and sold out, made-to-order, members-only, early access), coupons,
shipping zones, ~90 people with a deep referral tree, orders in every status,
membership payments, commissions (pending/available), cash-outs in every
status, fraud checks, badges, trading signals with results, Telegram
subscribers, an activity log and dashboard history. It also adds one of each
newer feature: a live flash sale, a bundle, a contest, a team challenge, a
pre-order, a download (PDF guide), a gift card and a custom order request.

- Every demo row is marked `is_demo`. **Admin → Demo data** has the
  **Show demo data** switch (off hides it everywhere, nothing is deleted)
  and **Delete all demo data** (Super Admin, type `DELETE DEMO DATA`).
- Demo and real money are never added together: the admin dashboard shows
  one data set at a time (Demo / Real).
- Demo phones are `+251 900 1…` and demo emails end in `.test`: no SMS,
  email or Telegram message is ever sent to them.
- The seeder refuses to run when `APP_ENV=production`, and demo accounts
  can't sign in on a production shop.
- Photos: real free photos from Pexels, see `database/seeders/demo-photos/CREDITS.md`.

### Demo accounts
Password for all of them: **`kulmis demo 2026`**

| Who | Sign in with | Where |
|-----|--------------|-------|
| Super Admin | `demo-super_admin@kulmis.test` | `/admin` (set up 2FA with your phone app on first sign-in) |
| Manager | `demo-manager@kulmis.test` | `/admin` |
| Finance | `demo-finance@kulmis.test` | `/admin` |
| Fulfilment | `demo-fulfilment@kulmis.test` | `/admin` |
| Support | `demo-support@kulmis.test` | `/admin` |
| Member with a big team (Selam) | `demo-member@kulmis.test` | `/account` |
| Brand-new member (Dawit) | `demo-new@kulmis.test` | `/account` |
| Team leader (Abebe) | `demo-team-abebe@kulmis.test` | `/account` |
| Shopper without membership | `demo-shopper-0@kulmis.test` | `/account` |

## Without Docker
You need PHP 8.3 (pdo_mysql, bcmath, intl, gd, zip, sodium), Composer and
MySQL/MariaDB.
```bash
cp .env.example .env    # set DB_HOST=127.0.0.1 and MAIL_MAILER=log
composer install
php artisan key:generate
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder
php artisan storage:link
php artisan km:create-admin you@example.com
php artisan serve                 # http://127.0.0.1:8000
php artisan schedule:work         # second terminal: cron + queue
```

## Front-end (only if you change them)
```bash
npm ci --ignore-scripts && npm run build                  # storefront → public/build
cd admin && npm ci --ignore-scripts && npm run build      # admin → public/admin-app
cd admin && npm run dev                                   # admin dev server with hot reload
```
Commit the rebuilt `public/build` / `public/admin-app` with your change.

## Try the payment flows without real money
- **Transfer + proof:** pay "by Telebirr" on `/pay/{order}`, upload any image,
  then approve it in **Admin → Payments**.

## Checks (run before every push)
```bash
composer check            # pint --test + all tests (SQLite)
scripts/test-mysql.sh     # the same tests against MySQL (Docker on port 3307)
php artisan km:doctor     # setup checklist
```

# Running Kulmis Market locally

## With Docker (recommended)
```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan db:seed --class=DemoSeeder   # 8 demo products with photos
docker compose exec app php artisan storage:link
docker compose exec app php artisan km:create-admin you@example.com
```
- Shop: http://localhost:8080
- Admin: http://localhost:8080/admin (sign in → set up 2FA with your phone app)
- Mail inbox (Mailpit): http://localhost:8025
- MySQL from your computer: `127.0.0.1:3307` (user `kulmis`, password `secret`)

The `queue` and `scheduler` containers run jobs and cron. SMS go to the log
(`storage/logs`) until you set an SMS provider.

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
- **Chapa:** put your Chapa **test** secret key in `CHAPA_SECRET_KEY`. Test
  payments in Chapa's checkout call back to your machine only through a
  tunnel (e.g. `cloudflared`). Without one, use **Admin → Payments → Check
  with Chapa** after paying.
- **Transfer + proof:** pay "by Telebirr" on `/pay/{order}`, upload any image,
  then approve it in **Admin → Payments**.

## Checks (run before every push)
```bash
composer check            # pint --test + all tests (SQLite)
scripts/test-mysql.sh     # the same tests against MySQL (Docker on port 3307)
php artisan km:doctor     # setup checklist
```

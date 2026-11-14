# Deploying Kulmis Market

The same code runs on **shared hosting** (cPanel/Plesk with PHP 8.3 and
MySQL) and on a **VPS** (nginx + php-fpm). The built storefront and admin
assets are committed, so the server needs **no Node.js**.

## Requirements
- PHP **8.3+** with pdo_mysql, bcmath, intl, gd (with WebP; AVIF optional),
  zip, mbstring, sodium, openssl
- MySQL 8 / MariaDB 10.6+
- HTTPS (Chapa and Telegram webhooks need it)
- Cron that runs every minute

---

## Shared hosting (cPanel)
1. **Upload the code** (git clone or upload a zip) to a folder *outside*
   `public_html`, e.g. `~/kulmis-market`.
2. **Point the domain's document root at `~/kulmis-market/public`** (cPanel →
   Domains → Document Root). If you can't change it, put the contents of
   `public/` in `public_html` and change the two paths in `public_html/index.php`
   to point at `~/kulmis-market`.
3. **Install PHP packages:** `composer install --no-dev --optimize-autoloader`
   (cPanel Terminal), or upload a `vendor/` folder built on your computer.
4. **Create `.env`:** copy `.env.example` → `.env` and set:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-domain
   DB_HOST=localhost   DB_DATABASE=…   DB_USERNAME=…   DB_PASSWORD=…
   SESSION_SECURE_COOKIE=true
   MAIL_MAILER=smtp    MAIL_HOST=…  MAIL_USERNAME=…  MAIL_PASSWORD=…  MAIL_FROM_ADDRESS=…
   QUEUE_VIA_SCHEDULER=true
   ```
5. **Run once:**
   ```bash
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force          # shipping zones, couriers, default texts
   php artisan storage:link
   php artisan km:create-admin you@example.com
   php artisan km:backup-key            # put the line it prints in .env
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
6. **Cron** (cPanel → Cron Jobs, every minute):
   ```
   * * * * * cd ~/kulmis-market && php artisan schedule:run >> /dev/null 2>&1
   ```
   This one line runs everything: the queue (messages, SMS), membership
   expiry and reminders, Telegram removals, commission release, cash-out
   reminders, loyalty rewards, page-cache refresh and the nightly backup.
7. **Page cache:** `public/.htaccess` already serves cached guest pages
   without PHP (Apache `mod_rewrite`).
8. Open `https://your-domain/admin`, sign in, set up 2FA, then check
   **Admin → Status** and run `php artisan km:doctor`.

## VPS (Ubuntu + nginx)
1. Install PHP 8.3-fpm + extensions, MySQL/MariaDB, nginx, composer,
   certbot.
2. Clone to `/var/www/kulmis-market`, then do steps 3–5 above. Set
   `QUEUE_VIA_SCHEDULER=false`.
3. nginx: use `docker/nginx/default.conf` as the server block (change
   `fastcgi_pass` to `unix:/run/php/php8.3-fpm.sock` and `root` to
   `/var/www/kulmis-market/public`). It includes the static page cache rules.
   Get HTTPS with `certbot --nginx`.
4. Cron line as above (as the `www-data` user).
5. Queue worker (systemd `/etc/systemd/system/kulmis-queue.service`):
   ```ini
   [Service]
   User=www-data
   WorkingDirectory=/var/www/kulmis-market
   ExecStart=/usr/bin/php artisan queue:work --tries=3 --max-time=3600
   Restart=always
   [Install]
   WantedBy=multi-user.target
   ```
   `systemctl enable --now kulmis-queue`
6. File permissions: `storage/` and `bootstrap/cache/` writable by
   `www-data`.

## Connect the services
| Service | What to do | `.env` |
|---|---|---|
| **Chapa** | In the Chapa dashboard: copy the secret key and set the webhook URL to `https://your-domain/api/v1/webhooks/chapa` with a webhook secret. Start with test keys. | `CHAPA_SECRET_KEY`, `CHAPA_WEBHOOK_SECRET` |
| **Telegram** | @BotFather → new bot. Create a private channel and add the bot as admin (invite + ban users). Get the channel id (`-100…`). Then `php artisan telegram:set-webhook`. | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_CHANNEL_ID`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_ADMIN_CHAT_ID` (your own chat, for cash-out reminders) |
| **SMS** | Any provider with a JSON API (`POST {to, message, sender}` + Bearer token). Different shape? Adapt `app/Notify/Sms/HttpSmsGateway.php`. | `SMS_DRIVER=http`, `SMS_URL`, `SMS_TOKEN`, `SMS_SENDER` |
| **Email** | Any SMTP (your host's, or a mail service). | `MAIL_*` |

## Updating
```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```
The page cache clears itself on changes. To clear it by hand, delete
`public/page-cache/`.

## Backups
- `php artisan km:backup` runs nightly at 02:17 when `BACKUP_KEY` is set. It
  includes the database, payment proofs and uploads, encrypted with
  libsodium, and keeps 14 days in `storage/app/backups`.
- **Copy the backups off the server** regularly (download or rsync), and
  keep `BACKUP_KEY` in your password manager.
- Restore: `php artisan km:backup-key --decrypt=storage/app/backups/kulmis-….enc`,
  then `tar -xzf` it and import `database.sql` with `mysql`.

## Security checklist (OWASP / ASVS L2)
- `APP_DEBUG=false`, HTTPS only, `SESSION_SECURE_COOKIE=true`
- Admin: 2FA required, session + CSRF, no API tokens for staff, roles and
  abilities, append-only activity log
- Rate limits on login, 2FA, checkout, tracking, password reset, webhooks
- Webhooks: Chapa signature + re-verify with Chapa; Telegram secret header
- Strict CSP (no inline scripts except one hashed), `X-Frame-Options: DENY`,
  `nosniff`, HSTS on https
- Secrets only in `.env`. Encrypted: 2FA secrets, payout account numbers,
  sessions, backups
- Guests: no session, no IP stored. Only essential cookies.
- Run `php artisan km:doctor` after every change to `.env`.

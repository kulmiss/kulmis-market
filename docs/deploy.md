# Deploying Kulmis Market

The same code runs on **shared hosting** (cPanel/Plesk with PHP 8.3 and
MySQL) and on a **VPS** (nginx + php-fpm). The built storefront and admin
assets are committed, so the server needs **no Node.js**.

## Requirements
- PHP **8.3+** with pdo_mysql, bcmath, intl, gd (with WebP; AVIF optional),
  zip, mbstring, sodium, openssl
- MySQL 8 / MariaDB 10.6+
- HTTPS (the Telegram webhook needs it)
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
  libsodium (XChaCha20-Poly1305) using **its own key** (`BACKUP_KEY`, not
  the app key), and keeps 14 days in `storage/app/backups`.
- **Copy the backups off the server** regularly (download or rsync), and
  keep `BACKUP_KEY` in your password manager. Without it a backup can't be
  opened — by anyone, including you.

### Restore test (do it once a month, and after any server change)
1. `php artisan km:backup` (or use last night's file).
2. `php artisan km:backup-verify` — decrypts the newest backup, unpacks it
   and checks the key tables are there. Add
   `--restore-to=restore_test` to load the dump into a separate scratch
   database (define a `restore_test` connection pointing at an empty
   database — never the live one); it then checks that the ledger in the
   backup balances to zero and counts users.
3. Expect `Backup OK — it can be restored.` Anything else: fix it today.

### Real restore (after a disaster)
1. Put the site in maintenance: `php artisan down`.
2. `php artisan km:backup-key --decrypt=storage/app/backups/kulmis-….enc`
3. `tar -xzf kulmis-….tar.gz` → `database.sql`, `app/private`, `app/public`.
4. `mysql -u … -p kulmis-market < database.sql`, copy `app/private` and
   `app/public` back into `storage/`.
5. `php artisan km:doctor`, then `php artisan up`.

## Production checklist
Run `php artisan km:doctor` after every deploy and every `.env` change. It
**fails (exit code 1) if anything is unsafe**, so a deploy script can stop:
- `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=info`
- HTTPS only: `APP_URL=https://…`, a TLS certificate, HSTS (sent
  automatically), Secure cookies (automatic with an https `APP_URL`)
- `SESSION_ENCRYPT=true`, `ADMIN_REQUIRE_2FA=true`, `ADMIN_PASSWORD_MIN=12`,
  every staff account with 2FA on
- `PASSWORD_PWNED_CHECK=true` (default in production)
- `BACKUP_KEY` set, and a backup in the last 26 hours
- File permissions: `chmod 600 .env`; the web server user owns `storage/`
  and `bootstrap/cache/` (`chmod -R 775`); everything else read-only.
  Never put `.env`, dumps or archives in `public/`.
- Hidden versions: nginx `server_tokens off` (in the provided config),
  PHP `expose_php=Off` and `display_errors=Off`.
- Admin path: 2FA is always required. For an extra lock, set
  `ADMIN_ALLOWED_IPS` to your own IP(s)/ranges; everyone else gets a 404
  for `/admin` and the admin API.
- The Telegram webhook secret, and `TELEGRAM_ADMIN_CHAT_ID` so
  you get security alerts.
- Composer: `composer install --no-dev --optimize-autoloader` on the server.

## Security checklist (OWASP / ASVS L2)
See `SECURITY_REPORT.md` for the full audit. In short:
- Accounts: strong passwords + common list, sign-in pause after 10 wrong
  tries, 2FA (staff required, customers optional), "Was this you?" notices,
  email change by link, admin idle timeout, sign out everywhere
  ([account-security.md](account-security.md))
- Money: double-entry ledger with row locks and idempotency keys; payout
  account 48 h wait + verification; daily limits; webhooks verified
  (signature, amount, currency, reference) and replays ignored
  ([wallet-cashout-fraud.md](wallet-cashout-fraud.md))
- Web: strict CSP, HSTS, secure cookies, CSRF, CORS locked, per-route rate
  limits, security.txt, safe uploads, secrets scrubbed from logs, gitleaks
  and dependency audits in CI ([web-security.md](web-security.md))
- Guests: no session, no IP stored, only essential cookies.

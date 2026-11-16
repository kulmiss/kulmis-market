---
title: Install on shared hosting (cPanel)
part: 1. Setup
order: 2
---
The built shop files are already in the code, so your hosting does **not** need Node.js.

1. **Upload the code** to a folder *outside* `public_html`, for example `~/kulmis-market` (cPanel → File Manager → Upload the zip → Extract).
2. **Point your domain to the `public` folder**: cPanel → Domains → your domain → Document Root = `kulmis-market/public`.
3. **Create a database**: cPanel → MySQL Databases → create a database and a user with all rights. Write down the names and the password.
4. **Install the PHP packages**: cPanel → Terminal:
   ```
   cd ~/kulmis-market
   composer install --no-dev --optimize-autoloader
   ```
5. **Create the settings file**: copy `.env.example` to `.env` and fill in:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-domain
   DB_DATABASE=…  DB_USERNAME=…  DB_PASSWORD=…
   MAIL_MAILER=smtp  MAIL_HOST=…  MAIL_USERNAME=…  MAIL_PASSWORD=…  MAIL_FROM_ADDRESS=…
   QUEUE_VIA_SCHEDULER=true
   ```
6. **Run once** (Terminal):
   ```
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force
   php artisan storage:link
   php artisan km:create-admin you@example.com
   php artisan km:backup-key
   php artisan optimize
   ```
   `km:backup-key` prints a line `BACKUP_KEY=…`: put it in `.env` **and** in your password manager.
7. **The cron line** (cPanel → Cron Jobs → every minute):
   ```
   * * * * * cd ~/kulmis-market && php artisan schedule:run >> /dev/null 2>&1
   ```
   This one line runs everything in the background: messages, membership ends, earnings becoming ready, reminders, the nightly backup.
8. Open `https://your-domain/admin` and sign in. Continue with [First sign-in and safety](setup-first-sign-in.md).

**Common mistakes**
- The domain points to `kulmis-market` instead of `kulmis-market/public` → you see a list of files or an error. Fix the Document Root.
- No cron line → messages are never sent and earnings never become ready.
- `APP_DEBUG=true` on the live shop → `km:doctor` shows red. Set it to `false`.

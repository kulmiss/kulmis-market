---
title: Install on a VPS (Ubuntu + nginx)
part: 1. Setup
order: 3
---
1. Install PHP 8.3-fpm (with pdo_mysql, bcmath, intl, gd, zip, mbstring, sodium), MySQL or MariaDB, nginx, composer and certbot.
2. Put the code in `/var/www/kulmis-market` and do steps 3 to 6 of [shared hosting](setup-install-shared-hosting.md). Set `QUEUE_VIA_SCHEDULER=false` in `.env`.
3. **nginx**: use `docker/nginx/default.conf` from the code as your server block. Change `root` to `/var/www/kulmis-market/public` and `fastcgi_pass` to `unix:/run/php/php8.3-fpm.sock`. Then `certbot --nginx` for HTTPS.
4. **Cron** for the `www-data` user:
   ```
   * * * * * cd /var/www/kulmis-market && php artisan schedule:run >> /dev/null 2>&1
   ```
5. **Queue worker** (sends messages fast): create `/etc/systemd/system/kulmis-queue.service`:
   ```
   [Service]
   User=www-data
   WorkingDirectory=/var/www/kulmis-market
   ExecStart=/usr/bin/php artisan queue:work --tries=3 --max-time=3600
   Restart=always
   [Install]
   WantedBy=multi-user.target
   ```
   then `systemctl enable --now kulmis-queue`.
6. `storage/` and `bootstrap/cache/` must be writable by `www-data`. `chmod 600 .env`.
7. Continue with [First sign-in and safety](setup-first-sign-in.md).

**Updating later**
```
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan up
```

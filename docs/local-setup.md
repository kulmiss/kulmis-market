# Running Kulmis Market locally

## With Docker (recommended)
```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```
- Site: http://localhost:8080
- Mail inbox (Mailpit): http://localhost:8025
- MySQL from your computer: `127.0.0.1:3307` (user `kulmis`, password `secret`)

The `queue` and `scheduler` containers run jobs and cron for you.

## Without Docker
You need PHP 8.3 (with pdo_mysql, bcmath, intl, gd, zip), Composer and MySQL 8.
```bash
cp .env.example .env    # set DB_HOST=127.0.0.1 and MAIL_MAILER=log
composer install
php artisan key:generate
php artisan migrate
php artisan serve
php artisan schedule:work   # in a second terminal: runs cron + drains the queue
```

## Checks (run before every push)
```bash
composer check   # pint --test + all tests
```
Tests run on in-memory SQLite by default. To test against MySQL the way CI does (Docker MySQL on port 3307):
```bash
scripts/test-mysql.sh
```

#!/usr/bin/env bash
# Run the test suite against a real MySQL/MariaDB, the way CI does.
# Defaults: host port 3307, test database "kulmis". Override with env vars.
set -euo pipefail
export DB_CONNECTION=mysql
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-3307}"
export DB_DATABASE="${DB_DATABASE:-kulmis}"
export DB_USERNAME="${DB_USERNAME:-kulmis}"
export DB_PASSWORD="${DB_PASSWORD:-secret}"
php artisan config:clear >/dev/null
php artisan test "$@"

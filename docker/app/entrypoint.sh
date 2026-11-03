#!/bin/sh
set -e

# Runs on container boot for both the app (php-fpm), worker, and scheduler.
# Database migrations are automatically run in isolated mode to prevent races.

cd /var/www/html

# Auto-generate APP_KEY if missing so the app boots smoothly
if [ -z "$APP_KEY" ]; then
    if [ ! -f storage/app_key.txt ]; then
        echo "base64:$(head -c 32 /dev/urandom | base64)" > storage/app_key.txt
        chown www-data:www-data storage/app_key.txt 2>/dev/null || true
    fi
    export APP_KEY=$(cat storage/app_key.txt)
fi

# Link public/storage -> storage/app/public for uploaded assets. Idempotent.
php artisan storage:link 2>/dev/null || true

# Bake the production caches. Safe to re-run on every boot.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run database migrations automatically so the user doesn't have to.
# --isolated ensures only one container runs it, avoiding races (the others get
# the lock-skip and exit 0). NOT swallowed: a real migration failure should crash
# the container loudly (set -e) so a bad deploy is visible, not silently boot the
# app against a half-migrated schema.
php artisan migrate --force --isolated

# Belt-and-suspenders: make sure the writable storage the app touches is owned
# by www-data — the user every process below now runs as. Idempotent and cheap.
chown -R www-data:www-data storage/app/private 2>/dev/null || true

# Drop from root (needed for the setup above) to www-data for the actual
# long-running process, so files it writes at runtime (exports, backups, logs)
# are owned by the web server's user and stay readable. This is what previously
# had to be done per-service in each compose file (`su www-data -c ...`), which
# drifted — the TLS stack was missing it and ran the worker as root. Doing it
# here fixes every stack at once and lets the compose commands stay plain.
#
# php-fpm is left alone: its master stays root and forks www-data workers itself.
# setpriv exec's the process directly (no forked child), so SIGTERM still reaches
# e.g. queue:work for a graceful shutdown. Skipped when already unprivileged.
if [ "$(id -u)" = "0" ] && [ "$1" != "php-fpm" ]; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups -- "$@"
fi

exec "$@"

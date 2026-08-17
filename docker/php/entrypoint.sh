#!/bin/sh
set -e

# Wait for Postgres to accept connections before running migrations - the
# php-fpm container can start well before the postgres container finishes
# initializing on a fresh volume.
if [ -n "$DB_HOST" ]; then
    echo "Waiting for database at $DB_HOST:${DB_PORT:-5432}..."
    until php -r "new PDO('pgsql:host=$DB_HOST;port=${DB_PORT:-5432};dbname=$DB_DATABASE', '$DB_USERNAME', '$DB_PASSWORD');" 2>/dev/null; do
        sleep 2
    done
    echo "Database is up."
fi

if [ ! -f /var/www/.env ]; then
    echo "No .env found in container - expected to be mounted/copied at build/deploy time." >&2
fi

php artisan migrate --force

# Config/route caching is safe to run on every boot - cheap, and guarantees the
# cache reflects whatever .env/code shipped in this image instead of a stale
# cache baked in at build time.
php artisan config:cache
php artisan route:cache

exec "$@"

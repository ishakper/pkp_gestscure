#!/bin/sh
set -e

echo "=== [PKP Secure] Starting Container Initialization ==="

# 1. Prepare Storage and Database Directories
mkdir -p /var/www/html/database \
         /var/www/html/storage/app \
         /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

# 2. Prepare the configured SQLite database file at runtime (never bake it into the image)
SQLITE_DATABASE_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ ! -f "$SQLITE_DATABASE_PATH" ]; then
    echo "Creating configured SQLite database file..."
    mkdir -p "$(dirname "$SQLITE_DATABASE_PATH")"
    touch "$SQLITE_DATABASE_PATH"
fi

# 3. Fix Ownership and Permissions for SQLite & Storage
echo "Setting storage & database permissions for www-data..."
chown -R www-data:www-data /var/www/html/database /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/database /var/www/html/storage /var/www/html/bootstrap/cache
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    chmod 664 "$SQLITE_DATABASE_PATH"
fi

# 4. Require a stable APP_KEY in production; generate only for non-production convenience
if [ -z "$APP_KEY" ]; then
    if [ "$APP_ENV" = "production" ]; then
        echo "ERROR: APP_KEY must be set in production." >&2
        exit 1
    fi
    echo "Warning: APP_KEY is not set. Generating a development application key..."
    php artisan key:generate --force
fi

# 5. Clear Old Caches to prevent stale schema/routes
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

# 6. Run Database Migrations
if [ "${SKIP_MIGRATIONS:-false}" = "true" ]; then
    echo "SKIP_MIGRATIONS=true: Skipping database migrations on container startup."
else
    echo "Running database migrations..."
    php artisan migrate --force
fi

# 7. Cache Configurations, Routes, and Views for Production
echo "Optimizing and caching Laravel configuration & routes..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "=== [PKP Secure] Initialization Complete. Starting Services ==="

# Execute the given command (supervisord)
exec "$@"

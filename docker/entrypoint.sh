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

# 2. Prepare SQLite Database File
if [ ! -f /var/www/html/database/database.sqlite ]; then
    echo "Creating SQLite database file at /var/www/html/database/database.sqlite..."
    touch /var/www/html/database/database.sqlite
fi

# 3. Fix Ownership and Permissions for SQLite & Storage
echo "Setting storage & database permissions for www-data..."
chown -R www-data:www-data /var/www/html/database /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/database /var/www/html/storage /var/www/html/bootstrap/cache
chmod 664 /var/www/html/database/database.sqlite

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
echo "Running database migrations..."
php artisan migrate --force

# 7. Cache Configurations, Routes, and Views for Production
echo "Optimizing and caching Laravel configuration & routes..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "=== [PKP Secure] Initialization Complete. Starting Services ==="

# Execute the given command (supervisord)
exec "$@"

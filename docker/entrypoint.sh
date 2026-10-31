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

# 2. Prepare Storage Directories
mkdir -p /var/www/html/database \
         /var/www/html/storage/app \
         /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

# 3. Fix Ownership and Permissions for Storage
echo "Setting storage & database permissions for www-data..."
chown -R www-data:www-data /var/www/html/database /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/database /var/www/html/storage /var/www/html/bootstrap/cache

# 4. Require stable APP_KEY in UAT and production. Never generate keys at startup.
if [ -z "$APP_KEY" ] && { [ "$APP_ENV" = "production" ] || [ "$APP_ENV" = "uat" ]; }; then
    echo "ERROR: APP_KEY must be set in $APP_ENV." >&2
    exit 1
fi

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY must be set before startup." >&2
    exit 1
fi

# 5. Clear Old Caches to prevent stale schema/routes
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

# 6. Run Database Migrations only when explicitly authorized.
# Production startup must not mutate schema by default.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "RUN_MIGRATIONS=true: Running database migrations..."
    php artisan migrate --force
else
    echo "RUN_MIGRATIONS is not true: Skipping database migrations on container startup."
fi

# 7. Cache Configurations, Routes, and Views for Production
echo "Optimizing and caching Laravel configuration & routes..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "=== [PKP Secure] Initialization Complete. Starting Services ==="

# Execute the given command (supervisord)
exec "$@"

#!/bin/sh
set -e

echo "=== [PKP SecureGate] Starting Container Initialization ==="

# 1. Prepare SQLite Database
mkdir -p /var/www/html/database
if [ ! -f /var/www/html/database/database.sqlite ]; then
    echo "Creating SQLite database file at /var/www/html/database/database.sqlite..."
    touch /var/www/html/database/database.sqlite
fi

# 2. Fix Directory Permissions
echo "Setting storage & database permissions for www-data..."
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database
chmod 664 /var/www/html/database/database.sqlite

# 3. Check / Generate APP_KEY if not configured
if [ -z "$APP_KEY" ]; then
    echo "Warning: APP_KEY is not set in environment. Generating a new application key..."
    php artisan key:generate --force || true
fi

# 4. Run Database Migrations
echo "Running database migrations..."
php artisan migrate --force

# 5. Check if database needs initial seeding (if admins table is empty)
ADMIN_COUNT=$(php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class); \$kernel->bootstrap(); echo \App\Models\Admin::count();" 2>/dev/null || echo "0")

if [ "$ADMIN_COUNT" = "0" ]; then
    echo "Database appears empty. Seeding initial admin and demo doors..."
    php artisan db:seed --force || true
fi

# 6. Cache Configurations, Routes, and Views
echo "Optimizing and caching Laravel configuration & routes..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "=== [PKP SecureGate] Initialization Complete. Starting Services ==="

# Execute the given command (supervisord)
exec "$@"

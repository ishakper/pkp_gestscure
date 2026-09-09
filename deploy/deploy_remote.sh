#!/bin/bash
set -euo pipefail

# ==============================================================================
# PKP SecureGate - Server Deployment & Docker Synchronization Script
# Target Host: 192.168.90.81 | Target Container: pkp_securegate_app
# ==============================================================================

APP_DIR="/var/www/pkp-securegate"
CONTAINER_NAME="pkp_securegate_app"
BACKUP_DIR="/var/backups/pkp-securegate"
RELEASE_TAR="${1:-release-securegate.tar.gz}"

echo "===================================================================="
echo "  PKP SECUREGATE - PRODUCTION DEPLOYMENT & SYNC"
echo "  Timestamp: $(date '+%Y-%m-%d %H:%M:%S')"
echo "===================================================================="

if [ ! -f "$RELEASE_TAR" ]; then
    echo "[ERROR] Release tarball $RELEASE_TAR not found in current directory!"
    exit 1
fi

echo "[1/6] Preparing backup and target directories..."
mkdir -p "$BACKUP_DIR" "$APP_DIR"

if [ -d "$APP_DIR/app" ]; then
    BACKUP_FILE="$BACKUP_DIR/backup-$(date +%Y%m%d%H%M%S).tar.gz"
    echo "Creating backup of current code to $BACKUP_FILE..."
    tar -czf "$BACKUP_FILE" -C "$APP_DIR" --exclude='database/database.sqlite' --exclude='storage/logs/*' . || true
fi

echo "[2/6] Extracting release files into $APP_DIR..."
tar -xzf "$RELEASE_TAR" -C "$APP_DIR"

echo "[3/6] Preserving environment and database persistence..."
if [ ! -f "$APP_DIR/.env" ] && [ -f "$APP_DIR/.env.example" ]; then
    echo "[WARN] No existing .env found. Copying .env.example (please verify production credentials!)"
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

# Ensure storage and cache permissions
mkdir -p "$APP_DIR/storage/logs" "$APP_DIR/storage/app" "$APP_DIR/storage/framework/cache" "$APP_DIR/storage/framework/sessions" "$APP_DIR/storage/framework/views" "$APP_DIR/bootstrap/cache"
chmod -R 777 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

echo "[4/6] Building persistent Docker image and restarting container..."
cd "$APP_DIR"
# Build image directly from extracted source to ensure changes persist across container recreation
docker compose build --no-cache app
docker compose up -d --force-recreate app

echo "[5/6] Executing Laravel optimization commands inside $CONTAINER_NAME..."
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

echo "[6/6] Verifying container health and persistence..."
docker compose exec -T app php artisan about
echo "===================================================================="
echo "[SUCCESS] Persistent deployment completed successfully!"
echo "Docker image 'pkp-securegate:latest' rebuilt and container recreated."
echo "===================================================================="

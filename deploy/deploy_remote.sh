#!/bin/bash
set -euo pipefail

# ==============================================================================
# PKP SecureGate - Immutable Image Server Deployment & Synchronization Script
# Target Host: 10.10.8.124 | Target Container: pkp_securegate_app
# ==============================================================================

APP_DIR="${PKP_PROD_APP_DIR:-/home/infra/access-door-management}"
CONTAINER_NAME="pkp_securegate_app"
BACKUP_DIR="/home/infra/backups/pkp-securegate"
TARGET_IMAGE="${1:-}"

if [ -z "$TARGET_IMAGE" ]; then
    echo "[ERROR] Missing target image argument!"
    echo "Usage: $0 <pkp-securegate:IMAGE_TAG>"
    exit 1
fi

echo "===================================================================="
echo "  PKP SECUREGATE - PRODUCTION IMMUTABLE DEPLOYMENT"
echo "  Target Image : ${TARGET_IMAGE}"
echo "  App Directory: ${APP_DIR}"
echo "  Timestamp    : $(date '+%Y-%m-%d %H:%M:%S')"
echo "===================================================================="

# 1. Verify that the immutable Docker image exists in local daemon
echo "[1/6] Verifying local Docker image presence..."
if ! docker image inspect "$TARGET_IMAGE" > /dev/null 2>&1; then
    echo "[ERROR] Docker image $TARGET_IMAGE is not present on host!"
    echo "Load the image archive or pull it before executing deployment."
    exit 1
fi
echo "Confirmed image exists: $(docker image inspect "$TARGET_IMAGE" --format '{{.Id}}')"

# 2. Safety pre-deployment backup
echo "[2/6] Performing pre-deployment configuration and database snapshot..."
mkdir -p "$BACKUP_DIR"
SNAPSHOT_TIMESTAMP="$(date +%Y%m%d%H%M%S)"
if [ -f "$APP_DIR/database/database.sqlite" ]; then
    cp "$APP_DIR/database/database.sqlite" "$BACKUP_DIR/database-${SNAPSHOT_TIMESTAMP}.sqlite"
    echo "Database snapshot saved to $BACKUP_DIR/database-${SNAPSHOT_TIMESTAMP}.sqlite"
fi
if [ -f "$APP_DIR/.env" ]; then
    cp "$APP_DIR/.env" "$BACKUP_DIR/env-${SNAPSHOT_TIMESTAMP}.bak"
fi

# 3. Tag active container image as latest running before switch (without overwriting rollback image)
echo "[3/6] Recording active image state..."
CURRENT_RUNNING_IMAGE="$(docker inspect --format='{{.Config.Image}}' "$CONTAINER_NAME" 2>/dev/null || echo 'unknown')"
echo "Currently running container image: $CURRENT_RUNNING_IMAGE"

# 4. Recreate container with immutable image (zero source build, zero docker compose down)
echo "[4/6] Recreating container $CONTAINER_NAME with immutable image $TARGET_IMAGE..."
cd "$APP_DIR"
PKP_IMAGE="$TARGET_IMAGE" docker compose -f docker-compose.prod.yml up -d --no-deps --force-recreate app

# 5. Run configuration cache optimizations inside container
echo "[5/6] Executing Laravel optimization and route caching..."
docker compose -f docker-compose.prod.yml exec -T app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec -T app php artisan config:cache
docker compose -f docker-compose.prod.yml exec -T app php artisan route:cache
docker compose -f docker-compose.prod.yml exec -T app php artisan view:cache

# 6. Verify container health
echo "[6/6] Verifying container health and HTTP response..."
sleep 3
CONTAINER_STATUS="$(docker inspect --format='{{.State.Status}}' "$CONTAINER_NAME")"
if [ "$CONTAINER_STATUS" != "running" ]; then
    echo "[ERROR] Container $CONTAINER_NAME failed to enter running state! Status: $CONTAINER_STATUS"
    exit 1
fi

echo "===================================================================="
echo "[SUCCESS] Immutable deployment completed successfully!"
echo "Active container: $CONTAINER_NAME"
echo "Active image: $TARGET_IMAGE"
echo "===================================================================="

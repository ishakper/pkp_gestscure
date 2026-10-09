#!/bin/bash
set -euo pipefail

# ==============================================================================
# PKP SecureGate - Server Deployment & Container Synchronization Script
# Target Host: 10.10.8.124 | Target Container: pkp_securegate_app
# Live Runtime: docker-compose.yml + deploy/docker-compose.release.yml
# ==============================================================================

APP_DIR="${PKP_PROD_APP_DIR:-/home/infra/access-door-management}"
CONTAINER_NAME="pkp_securegate_app"
TARGET_IMAGE="${1:-}"
ARCHIVE_PATH="${2:-}"
MIN_FREE_GB="${PKP_MIN_FREE_GB:-2}"

if [ -z "$TARGET_IMAGE" ]; then
    echo "[ERROR] Missing target image argument!" >&2
    echo "Usage: $0 <pkp-securegate:IMAGE_TAG> [ARCHIVE_PATH]" >&2
    exit 1
fi

echo "===================================================================="
echo "  PKP SECUREGATE - PRODUCTION IMMUTABLE DEPLOYMENT"
echo "  Target Image : ${TARGET_IMAGE}"
echo "  App Directory: ${APP_DIR}"
echo "  Timestamp    : $(date '+%Y-%m-%d %H:%M:%S')"
echo "===================================================================="

# 1. Load image archive if provided
if [ -n "$ARCHIVE_PATH" ] && [ -f "$ARCHIVE_PATH" ]; then
    echo "[1/6] Disk capacity precheck before image load..."
    MIN_FREE_KB=$(( MIN_FREE_GB * 1024 * 1024 ))
    AVAILABLE_KB="$(df -k "$APP_DIR" | tail -n 1 | awk '{print $4}')"
    if [ "$AVAILABLE_KB" -lt "$MIN_FREE_KB" ]; then
        echo "[ERROR] Disk space critically low for image load! Available: $(( AVAILABLE_KB / 1024 / 1024 )) GB, Required: ${MIN_FREE_GB} GB" >&2
        exit 2
    fi

    echo "Loading Docker image from archive: ${ARCHIVE_PATH}..."
    gzip -dc "$ARCHIVE_PATH" | docker load
    echo "Image loaded. Removing temporary archive to conserve disk..."
    rm -f "$ARCHIVE_PATH"
else
    echo "[1/6] No archive path provided; verifying image in local daemon..."
fi

# 2. Verify target image exists in local Docker daemon
echo "[2/6] Verifying image presence in local Docker daemon..."
if ! docker image inspect "$TARGET_IMAGE" > /dev/null 2>&1; then
    echo "[ERROR] Docker image '$TARGET_IMAGE' not found on local host!" >&2
    exit 1
fi
echo "Confirmed image: $(docker image inspect "$TARGET_IMAGE" --format '{{.Id}}')"

# 3. Synchronize release overlay to application directory
echo "[3/6] Synchronizing release overlay to ${APP_DIR}/deploy/..."
mkdir -p "${APP_DIR}/deploy"
if [ -f "/tmp/docker-compose.release.yml" ]; then
    cp -f "/tmp/docker-compose.release.yml" "${APP_DIR}/deploy/docker-compose.release.yml"
    rm -f "/tmp/docker-compose.release.yml"
fi

if [ ! -f "${APP_DIR}/deploy/docker-compose.release.yml" ]; then
    echo "[ERROR] Missing ${APP_DIR}/deploy/docker-compose.release.yml!" >&2
    exit 1
fi

# 4. Recreate web container atomically (zero build, zero compose down, alertStream untouched)
echo "[4/6] Recreating container $CONTAINER_NAME with live runtime compose..."
cd "$APP_DIR"
PKP_IMAGE="$TARGET_IMAGE" docker compose \
    -p access-door-management \
    --env-file "${APP_DIR}/.env" \
    -f "${APP_DIR}/docker-compose.yml" \
    -f "${APP_DIR}/deploy/docker-compose.release.yml" \
    up -d --no-deps --no-build app

# 5. Run Laravel configuration and route optimization inside container
echo "[5/6] Optimizing and caching Laravel configuration & routes..."
docker compose \
    -p access-door-management \
    -f "${APP_DIR}/docker-compose.yml" \
    exec -T app php artisan optimize:clear
docker compose \
    -p access-door-management \
    -f "${APP_DIR}/docker-compose.yml" \
    exec -T app php artisan config:cache
docker compose \
    -p access-door-management \
    -f "${APP_DIR}/docker-compose.yml" \
    exec -T app php artisan route:cache
docker compose \
    -p access-door-management \
    -f "${APP_DIR}/docker-compose.yml" \
    exec -T app php artisan view:cache

# 6. Verify container health against production APP_URL (default: http://127.0.0.1:8000)
TARGET_URL="${APP_URL:-http://127.0.0.1:8000}"
echo "[6/6] Verifying container health on ${TARGET_URL}/login..."
sleep 3
CONTAINER_STATUS="$(docker inspect --format='{{.State.Status}}' "$CONTAINER_NAME" 2>/dev/null || echo 'unknown')"
if [ "$CONTAINER_STATUS" != "running" ]; then
    echo "[ERROR] Container $CONTAINER_NAME failed to enter running state! Status: $CONTAINER_STATUS" >&2
    exit 1
fi

HTTP_STATUS="$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 5 "${TARGET_URL}/login" || echo "000")"
if [ "$HTTP_STATUS" != "200" ]; then
    echo "[WARN] Healthcheck returned HTTP status $HTTP_STATUS (expected 200)."
else
    echo "Healthcheck passed: HTTP $HTTP_STATUS on ${TARGET_URL}/login"
fi

echo "===================================================================="
echo "[SUCCESS] Immutable deployment completed successfully!"
echo "Active container: $CONTAINER_NAME"
echo "Active image: $TARGET_IMAGE"
echo "===================================================================="

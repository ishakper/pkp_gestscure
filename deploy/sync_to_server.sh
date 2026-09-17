#!/bin/bash
set -euo pipefail

# ==============================================================================
# PKP SecureGate - Immutable Image Remote Deployment Dispatcher
# Target Host: 10.10.8.124 (infra)
# Live Runtime: docker-compose.yml + deploy/docker-compose.release.yml
# ==============================================================================

PROD_HOST="${PKP_PROD_HOST:-10.10.8.124}"
PROD_USER="${PKP_PROD_USER:-infra}"
PROD_APP_DIR="${PKP_PROD_APP_DIR:-/home/infra/access-door-management}"
MIN_FREE_GB="${PKP_MIN_FREE_GB:-3}"
IMAGE_TAG="${1:-}"

if [ -z "$IMAGE_TAG" ]; then
    echo "Usage: $0 <IMAGE_TAG_OR_GIT_SHA>"
    echo "Example: $0 pkp-securegate:75abebd"
    exit 1
fi

# Normalize image tag format
if [[ "$IMAGE_TAG" != pkp-securegate:* ]]; then
    FULL_IMAGE="pkp-securegate:${IMAGE_TAG}"
else
    FULL_IMAGE="${IMAGE_TAG}"
fi

SAFE_TAG="$(echo "$FULL_IMAGE" | tr '/:' '_')"
ARCHIVE_NAME="${SAFE_TAG}.tar.gz"
LOCAL_ARCHIVE="/tmp/${ARCHIVE_NAME}"
REMOTE_ARCHIVE="/tmp/${ARCHIVE_NAME}"

echo "===================================================================="
echo "  PKP SECUREGATE - IMMUTABLE IMAGE DISPATCHER"
echo "  Target Host    : ${PROD_USER}@${PROD_HOST}"
echo "  App Directory  : ${PROD_APP_DIR}"
echo "  Target Image   : ${FULL_IMAGE}"
echo "  Min Free Disk  : ${MIN_FREE_GB} GB"
echo "  Timestamp      : $(date '+%Y-%m-%d %H:%M:%S')"
echo "===================================================================="

# 1. Verify local image presence
echo "[1/6] Checking local Docker image presence..."
if ! docker image inspect "$FULL_IMAGE" >/dev/null 2>&1; then
    echo "[ERROR] Local image '$FULL_IMAGE' not found! Build it first." >&2
    exit 1
fi

# 2. Remote disk preflight check (fail-closed)
echo "[2/6] Performing remote disk safety preflight on ${PROD_HOST}..."
MIN_FREE_KB=$(( MIN_FREE_GB * 1024 * 1024 ))
REMOTE_FREE_KB="$(ssh -o ConnectTimeout=10 "${PROD_USER}@${PROD_HOST}" \
    "df -k /tmp | tail -n 1 | awk '{print \$4}'" 2>/dev/null || echo "0")"

if [ "$REMOTE_FREE_KB" -lt "$MIN_FREE_KB" ]; then
    REMOTE_FREE_GB=$(( REMOTE_FREE_KB / 1024 / 1024 ))
    echo "[ERROR] Insufficient disk space on ${PROD_HOST}:/tmp!" >&2
    echo "Required: ${MIN_FREE_GB} GB, Available: ${REMOTE_FREE_GB} GB (${REMOTE_FREE_KB} KB)." >&2
    echo "Aborting deployment to protect production stability without auto-pruning." >&2
    exit 2
fi
echo "Remote disk space confirmed safe: $(( REMOTE_FREE_KB / 1024 / 1024 )) GB available in /tmp."

# 3. Export image archive locally
echo "[3/6] Exporting image archive to ${LOCAL_ARCHIVE}..."
mkdir -p /tmp
docker save "$FULL_IMAGE" | gzip > "$LOCAL_ARCHIVE"
ARCHIVE_SIZE_MB=$(du -m "$LOCAL_ARCHIVE" | awk '{print $1}')
echo "Image archive created: ${ARCHIVE_SIZE_MB} MB."

# 4. Transfer deployment artifacts and image archive
echo "[4/6] Transferring archive and deployment manifests to ${PROD_HOST}..."
scp -o ConnectTimeout=15 \
    "$LOCAL_ARCHIVE" \
    deploy/deploy_remote.sh \
    deploy/docker-compose.release.yml \
    "${PROD_USER}@${PROD_HOST}:/tmp/"

# Clean up local archive
rm -f "$LOCAL_ARCHIVE"

# 5. Execute remote deployment
echo "[5/6] Executing remote deployment script on ${PROD_HOST}..."
ssh -o ConnectTimeout=15 "${PROD_USER}@${PROD_HOST}" \
    "chmod +x /tmp/deploy_remote.sh && \
     PKP_PROD_APP_DIR='${PROD_APP_DIR}' \
     /tmp/deploy_remote.sh '${FULL_IMAGE}' '${REMOTE_ARCHIVE}'"

echo "===================================================================="
echo "[SUCCESS] Remote deployment execution finished for ${FULL_IMAGE}."
echo "===================================================================="

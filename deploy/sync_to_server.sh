#!/bin/bash
set -euo pipefail

# ==============================================================================
# PKP SecureGate - Immutable Image Remote Deployment Dispatcher
# Target Host: 10.10.8.124 (infra)
# ==============================================================================

PROD_HOST="${PKP_PROD_HOST:-10.10.8.124}"
PROD_USER="${PKP_PROD_USER:-infra}"
PROD_APP_DIR="${PKP_PROD_APP_DIR:-/home/infra/access-door-management}"
IMAGE_TAG="${1:-}"

if [ -z "$IMAGE_TAG" ]; then
    echo "Usage: $0 <IMAGE_TAG_OR_GIT_SHA>"
    echo "Example: $0 b39bbcec061c710d486a6de32eabd73dd06b5a06"
    exit 1
fi

# Normalize image tag format
if [[ "$IMAGE_TAG" != pkp-securegate:* ]]; then
    FULL_IMAGE="pkp-securegate:${IMAGE_TAG}"
else
    FULL_IMAGE="${IMAGE_TAG}"
fi

echo "===================================================================="
echo "  PKP SECUREGATE - IMMUTABLE IMAGE REMOTE DEPLOYMENT"
echo "  Target Host : ${PROD_USER}@${PROD_HOST}"
echo "  App Dir     : ${PROD_APP_DIR}"
echo "  Image       : ${FULL_IMAGE}"
echo "  Timestamp   : $(date '+%Y-%m-%d %H:%M:%S')"
echo "===================================================================="

# Copy deployment runner script to remote host
scp -o ConnectTimeout=10 deploy/deploy_remote.sh "${PROD_USER}@${PROD_HOST}:/tmp/deploy_remote.sh"

# Execute remote deployment with explicit immutable image tag
ssh -o ConnectTimeout=10 "${PROD_USER}@${PROD_HOST}" \
    "chmod +x /tmp/deploy_remote.sh && PKP_PROD_APP_DIR='${PROD_APP_DIR}' /tmp/deploy_remote.sh '${FULL_IMAGE}'"

echo "[SUCCESS] Remote deployment execution finished for ${FULL_IMAGE}."

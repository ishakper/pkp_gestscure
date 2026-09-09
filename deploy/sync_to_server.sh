#!/bin/bash
set -euo pipefail

# ==============================================================================
# PKP SecureGate - Push Release to Server 192.168.90.81
# ==============================================================================

SERVER_IP="${1:-192.168.90.81}"
REMOTE_USER="${2:-root}"
RELEASE_TAR="release-securegate.tar.gz"

echo "Deploying $RELEASE_TAR to ${REMOTE_USER}@${SERVER_IP}..."

if [ ! -f "$RELEASE_TAR" ]; then
    echo "[ERROR] $RELEASE_TAR does not exist! Generate it first."
    exit 1
fi

echo "Copying release tarball and deploy script to ${SERVER_IP}..."
scp -o ConnectTimeout=5 "$RELEASE_TAR" deploy/deploy_remote.sh "${REMOTE_USER}@${SERVER_IP}:/tmp/"

echo "Executing remote deployment..."
ssh -o ConnectTimeout=5 "${REMOTE_USER}@${SERVER_IP}" "chmod +x /tmp/deploy_remote.sh && /tmp/deploy_remote.sh /tmp/$RELEASE_TAR"

echo "[SUCCESS] Deployment to ${SERVER_IP} completed."

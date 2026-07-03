#!/bin/bash

# Script to apply settings on server server@xdexboard.com
# Usage: ./deploy.sh

set -e

SERVER="server@xdexboard.com"
REMOTE_PATH="/opt/xboard-api"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=========================================="
echo "Deploy Email Server Setup"
echo "Server: ${SERVER}"
echo "=========================================="

# Check connection
echo ""
echo "1. Checking server connection..."
ssh -o ConnectTimeout=5 ${SERVER} "echo 'Connection successful'" || {
    echo "Error: Failed to connect to server ${SERVER}"
    echo "Check SSH access and settings"
    exit 1
}

# Copy files
echo ""
echo "2. Copying files to server..."
rsync -avz --progress \
    "${SCRIPT_DIR}/" \
    ${SERVER}:${REMOTE_PATH}/scripts/email-server/ \
    --exclude='*.example' \
    --exclude='README.md' \
    || {
    echo "Error copying files"
    exit 1
}

# Installation
echo ""
echo "3. Running installation on server..."
echo "WARNING: Installation requires root privileges"
echo "Continue? (y/n)"
read -r response
if [[ "$response" =~ ^([yY][eE][sS]|[yY])$ ]]; then
    ssh ${SERVER} "cd ${REMOTE_PATH}/scripts/email-server && sudo bash install.sh"
else
    echo "Installation cancelled"
    exit 0
fi

echo ""
echo "=========================================="
echo "Deploy completed"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Configure DNS records (see install.sh output)"
echo "2. Get SSL certificate (if not already obtained):"
echo "   ssh ${SERVER} 'sudo certbot certonly --standalone -d xdexboard.com -d mail.xdexboard.com'"
echo "3. Test email sending"
echo ""


#!/usr/bin/env bash
# Install logrotate for xboard-email verifier log (run on mail server with sudo).
#
#   sudo bash /var/www/xboard-api/scripts/email-server/install-logrotate.sh

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/xboard-api}"
SRC="$APP_DIR/scripts/email-server/logrotate-xboard-email.conf"

if [[ ! -f "$SRC" ]]; then
  echo "Config not found: $SRC" >&2
  exit 1
fi

mkdir -p /var/log/xboard-email
chown vmail:vmail /var/log/xboard-email 2>/dev/null || true

cp "$SRC" /etc/logrotate.d/xboard-email
chmod 644 /etc/logrotate.d/xboard-email
echo "Installed /etc/logrotate.d/xboard-email (daily, rotate 7, size 10M cap)"

#!/usr/bin/env bash
# Install Laravel scheduler cron for xboard-api (run on server as a user with sudo).
# Tasks are defined in bootstrap/app.php; cron must call schedule:run every minute.
#
# Usage (on xrpchange.site):
#   sudo bash /var/www/xboard-api/scripts/install-laravel-scheduler-cron.sh
#
# Runs as www-data — same user as PHP-FPM (file permissions, .env, storage/logs).
#
# Log rotation: scripts/logrotate-xboard-api.conf → /etc/logrotate.d/xboard-api

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/xboard-api}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
CRON_USER="${CRON_USER:-www-data}"
LOG_FILE="${LOG_FILE:-storage/logs/scheduler.log}"
CRON_COMMENT="# xboard-api Laravel schedule:run (see bootstrap/app.php)"

if [[ ! -f "$APP_DIR/artisan" ]]; then
  echo "artisan not found under $APP_DIR" >&2
  exit 1
fi

LOGROTATE_SRC="$APP_DIR/scripts/logrotate-xboard-api.conf"
if [[ -f "$LOGROTATE_SRC" ]]; then
  cp "$LOGROTATE_SRC" /etc/logrotate.d/xboard-api
  chmod 644 /etc/logrotate.d/xboard-api
  echo "Installed logrotate: /etc/logrotate.d/xboard-api (daily, rotate 7)"
else
  echo "WARN: logrotate config not found: $LOGROTATE_SRC" >&2
fi

if ! id "$CRON_USER" &>/dev/null; then
  echo "user $CRON_USER does not exist" >&2
  exit 1
fi

if ! sudo -u "$CRON_USER" "$PHP_BIN" -v &>/dev/null; then
  echo "$CRON_USER cannot run $PHP_BIN" >&2
  exit 1
fi

sudo -u "$CRON_USER" bash -c "cd '$APP_DIR' && $PHP_BIN artisan schedule:list" >/dev/null

CRON_LINE="* * * * * cd $APP_DIR && $PHP_BIN artisan schedule:run >> $LOG_FILE 2>&1"

EXISTING="$(sudo crontab -u "$CRON_USER" -l 2>/dev/null || true)"
if echo "$EXISTING" | grep -Fq "xboard-api Laravel schedule:run"; then
  echo "Crontab for $CRON_USER already contains xboard-api scheduler; updating entries."
  EXISTING="$(echo "$EXISTING" | grep -Fv "xboard-api Laravel schedule:run" | grep -Fv "artisan schedule:run" || true)"
fi

{
  echo "$EXISTING" | sed '/^[[:space:]]*$/d'
  echo "$CRON_COMMENT"
  echo "$CRON_LINE"
} | sudo crontab -u "$CRON_USER" -

echo "Installed for $CRON_USER:"
sudo crontab -u "$CRON_USER" -l | grep -F "xboard-api" || true
echo "Log: $APP_DIR/$LOG_FILE"

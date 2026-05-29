#!/usr/bin/env bash
# Read-only deploy pull for xboard-api on xrpchange.site
set -euo pipefail
APP_DIR=/var/www/xboard-api
cd "$APP_DIR"
git fetch origin
git checkout -f origin/main
composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
echo "xboard-api: $(git rev-parse --short HEAD) at $(date -u +%Y-%m-%dT%H:%M:%SZ)"

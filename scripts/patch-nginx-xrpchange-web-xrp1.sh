#!/usr/bin/env bash
# Add xrpchange.site HTTPS web proxy to khasanov@xrp1 xboard.conf (dev.xrpchange.site retired).
# Run on xrp1 with sudo. Example:
#   scp scripts/patch-nginx-xrpchange-web-xrp1.sh khasanov@xrp1:/tmp/
#   ssh khasanov@xrp1 'sudo bash /tmp/patch-nginx-xrpchange-web-xrp1.sh'

set -euo pipefail

CONF=/etc/nginx/sites-available/xboard.conf
MARKER="# HTTPS — Kotlin/JS web (xrpchange.site)"

if grep -qF "$MARKER" "$CONF"; then
  echo "Already patched: $MARKER"
  exit 0
fi

cp -a "$CONF" "${CONF}.bak-xrpchange-web-$(date +%Y%m%d%H%M%S)"

# HTTP redirect block: add apex web names (API names already present).
sed -i 's/server_name sandbox.api.xrpchange.site api.xrpchange.site dev.api.xrpchange.site;/server_name xrpchange.site sandbox.api.xrpchange.site api.xrpchange.site dev.api.xrpchange.site;/' "$CONF"

cat >>"$CONF" <<'NGINX'

# HTTPS — Kotlin/JS web (xrpchange.site) → XAMPP on dev PC (192.168.1.31:80)
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name xrpchange.site;

    ssl_certificate /etc/letsencrypt/live/api.xrpchange.site/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.xrpchange.site/privkey.pem;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    ssl_session_tickets off;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        proxy_pass http://192.168.1.31:80;
        proxy_set_header Host xrpchange.site;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_buffering off;
        proxy_request_buffering off;
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }
}
NGINX

nginx -t
systemctl reload nginx
echo "OK: xrpchange.site web block added to $CONF"

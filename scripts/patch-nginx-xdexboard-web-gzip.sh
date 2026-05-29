#!/usr/bin/env bash
# Patch xdexboard.com static block: gzip + dedicated .mjs location (production server).
set -euo pipefail
CONF=/etc/nginx/sites-enabled/xdexboard.conf
if grep -q 'location ~* \\.mjs$' "$CONF"; then
  echo "already patched"
  exit 0
fi
python3 << 'PY'
from pathlib import Path
p = Path("/etc/nginx/sites-enabled/xdexboard.conf")
text = p.read_text()
old = """    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \\.(?:css|js|mjs|wasm|woff2?|ttf|svg|png|jpe?g|gif|ico|json|webmanifest)$ {"""
new = """    gzip on;
    gzip_vary on;
    gzip_comp_level 6;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/javascript application/json text/javascript;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \\.mjs$ {
        types { }
        default_type application/javascript;
        try_files $uri =404;
        expires 7d;
        add_header Cache-Control "public";
        add_header X-Robots-Tag "noindex, nofollow, noarchive, nosnippet, noimageindex" always;
    }

    location ~* \\.(?:css|js|wasm|woff2?|ttf|svg|png|jpe?g|gif|ico|json|webmanifest)$ {"""
if old not in text:
    raise SystemExit("pattern not found in xdexboard.conf")
p.write_text(text.replace(old, new, 1))
print("patched")
PY

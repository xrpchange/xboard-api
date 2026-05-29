#!/usr/bin/env bash
# Patch xdexboard.com static block: no-cache index.html + gzip wasm (production server).
set -euo pipefail
CONF=/etc/nginx/sites-enabled/xdexboard.conf
python3 << 'PY'
from pathlib import Path
p = Path("/etc/nginx/sites-enabled/xdexboard.conf")
text = p.read_text()
if "location = /index.html" not in text:
    old = """    gzip_types text/plain text/css application/javascript application/json text/javascript;

    location / {"""
    new = """    gzip_types text/plain text/css application/javascript application/json text/javascript application/wasm;

    location = /index.html {
        add_header Cache-Control "no-cache, must-revalidate" always;
        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
        add_header X-Frame-Options SAMEORIGIN always;
        add_header X-Content-Type-Options nosniff always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header X-Robots-Tag "noindex, nofollow, noarchive, nosnippet, noimageindex" always;
        try_files /index.html =404;
    }

    location / {"""
    if old not in text:
        # already has gzip block from prior patch — try alternate anchor
        old2 = """    gzip_types text/plain text/css application/javascript application/json text/javascript;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \\.mjs$ {"""
        new2 = """    gzip_types text/plain text/css application/javascript application/json text/javascript application/wasm;

    location = /index.html {
        add_header Cache-Control "no-cache, must-revalidate" always;
        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
        add_header X-Frame-Options SAMEORIGIN always;
        add_header X-Content-Type-Options nosniff always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header X-Robots-Tag "noindex, nofollow, noarchive, nosnippet, noimageindex" always;
        try_files /index.html =404;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \\.mjs$ {"""
        if old2 not in text:
            raise SystemExit("pattern not found in xdexboard.conf")
        text = text.replace(old2, new2, 1)
    else:
        text = text.replace(old, new, 1)
    p.write_text(text)
    print("patched index.html + wasm gzip")
else:
    if "application/wasm" not in text:
        text = text.replace(
            "gzip_types text/plain text/css application/javascript application/json text/javascript;",
            "gzip_types text/plain text/css application/javascript application/json text/javascript application/wasm;",
            1,
        )
        p.write_text(text)
        print("patched wasm gzip only")
    else:
        print("already patched")
PY

#!/usr/bin/env bash
set -euo pipefail
CONF=/etc/nginx/sites-enabled/xdexboard.conf
python3 << 'PY'
from pathlib import Path
p = Path("/etc/nginx/sites-enabled/xdexboard.conf")
text = p.read_text()
text = text.replace("expires 7d;\n        add_header Cache-Control \"public\";", "add_header Cache-Control $xboard_web_asset_cache always;")
text = text.replace('add_header Cache-Control "public";\n        add_header X-Robots-Tag', 'add_header Cache-Control $xboard_web_asset_cache always;\n        add_header X-Robots-Tag')
if "$xboard_web_asset_cache" not in text:
    raise SystemExit("cache map variable not wired in xdexboard.conf")
p.write_text(text)
print("ok")
PY

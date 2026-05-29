#!/usr/bin/env bash
# Deploy fixed index.html to xdexboard.com preserving live bundle stamp meta tags.
set -euo pipefail
LIVE_INDEX=/tmp/xdex-live-index.html
REMOTE=server@xrpchange.site
LOCAL_SRC="${1:?usage: $0 <path-to-index.html>}"
curl -fsS "https://xdexboard.com/index.html" -o "$LIVE_INDEX"
STAMP=$(grep -oP '(?<=meta name="xboard-web-bundle" content=")[^"]+' "$LIVE_INDEX" | head -1)
SW=$(grep -oP '(?<=meta name="xboard-sw-cache" content=")[^"]+' "$LIVE_INDEX" | head -1)
UTC=$(grep -oP '(?<=meta name="xboard-web-build-utc" content=")[^"]+' "$LIVE_INDEX" | head -1)
OUT=/tmp/xdex-index-deploy.html
python3 << PY
from pathlib import Path
src = Path("$LOCAL_SRC").read_text(encoding="utf-8")
for old, new, label in [
    ('__XBUNDLE_STAMP__', '$STAMP', 'stamp'),
    ('__SW_CACHE_ID__', '$SW', 'sw'),
    ('__BUILD_UTC__', '$UTC', 'utc'),
]:
    if old in src:
        src = src.replace(old, new)
    elif label == 'stamp' and 'meta name="xboard-web-bundle"' in src:
        import re
        src = re.sub(
            r'(<meta name="xboard-web-bundle" content=")[^"]+(")',
            r'\1' + '$STAMP' + r'\2',
            src,
            count=1,
        )
Path("$OUT").write_text(src, encoding="utf-8")
print("prepared", "$OUT", "stamp=$STAMP")
PY
scp -o ConnectTimeout=15 "$OUT" "$REMOTE:/tmp/xdex-index-deploy.html"
ssh -o BatchMode=yes -o ConnectTimeout=15 "$REMOTE" "sudo cp /var/www/xdexboard-web/index.html /var/www/xdexboard-web/index.html.bak-shell-gen4 && sudo install -m 664 -o www-data -g www-data /tmp/xdex-index-deploy.html /var/www/xdexboard-web/index.html && echo deployed"

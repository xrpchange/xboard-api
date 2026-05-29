#!/usr/bin/env bash
# Register one read-only deploy key on one GitHub repo (uses local git credential).
set -euo pipefail
REPO="${1:?usage: $0 <repo-name> <public-key-line>}"
PUBKEY="${2:?usage: $0 <repo-name> <public-key-line>}"
TOKEN=$(printf 'protocol=https\nhost=github.com\n\n' | git credential fill | awk -F= '/^password=/{print $2}')
if [[ -z "$TOKEN" ]]; then
  echo "No GitHub token from git credential" >&2
  exit 1
fi
BODY=$(printf '{"title":"xrpchange.site-readonly","key":"%s","read_only":true}' "$PUBKEY")
curl -sS -X POST \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Accept: application/vnd.github+json" \
  -H "X-GitHub-Api-Version: 2022-11-28" \
  "https://api.github.com/repos/xrpchange/${REPO}/keys" \
  -d "$BODY"

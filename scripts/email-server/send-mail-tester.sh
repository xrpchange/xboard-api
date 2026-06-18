#!/usr/bin/env bash
set -euo pipefail
TO="${1:?usage: $0 test-xxx@srv1.mail-tester.com}"
NOREPLY_PASS="${NOREPLY_PASS:?set NOREPLY_PASS}"
ADMIN_PASS="${ADMIN_PASS:?set ADMIN_PASS}"

send_one() {
  local user="$1" pass="$2" subject="$3" body="$4"
  swaks --server 127.0.0.1:465 \
    --to "$TO" \
    --from "$user" \
    --auth LOGIN --auth-user "$user" --auth-password "$pass" \
    --tls-on-connect \
    --header "Subject: $subject" \
    --body "$body"
}

send_one 'noreply@xdexboard.com' "$NOREPLY_PASS" \
  'XBoard mail-tester noreply' \
  "Transactional test from noreply@xdexboard.com at $(date -u +%Y-%m-%dT%H:%M:%SZ) UTC."

sleep 2

send_one 'admin@xdexboard.com' "$ADMIN_PASS" \
  'XBoard mail-tester admin' \
  "Operator mailbox test from admin@xdexboard.com at $(date -u +%Y-%m-%dT%H:%M:%SZ) UTC."

echo "Done. Two messages sent to $TO"

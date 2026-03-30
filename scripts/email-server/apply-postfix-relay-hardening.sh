#!/usr/bin/env bash
# Postfix: закрытие open relay (compatibility_level), лимиты очереди, проверка.
# Запуск на почтовом сервере: sudo bash apply-postfix-relay-hardening.sh
# Идемпотентен: повторный запуск безопасен.
set -euo pipefail

if [[ "${EUID:-0}" -ne 0 ]]; then
  echo "Run as root: sudo $0" >&2
  exit 1
fi

echo "Applying Postfix relay/queue hardening via postconf..."

# См. docs/POSTFIX_RELAY_AND_QUEUE.md
postconf -e 'compatibility_level=3.6'
postconf -e 'maximal_queue_lifetime=10m'
postconf -e 'bounce_queue_lifetime=10m'

postconf compatibility_level maximal_queue_lifetime bounce_queue_lifetime smtpd_relay_restrictions

postfix check
systemctl reload postfix

echo "Done. If you changed compatibility_level for the first time, consider: systemctl restart postfix"
echo "Optional: block abusive IP(s) with iptables and iptables-save; see docs/POSTFIX_RELAY_AND_QUEUE.md"

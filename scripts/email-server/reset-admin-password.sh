#!/usr/bin/env bash
set -euo pipefail
PASSWD_ADMIN="/etc/dovecot/passwd.admin"
ADMIN_PASS="$(openssl rand -base64 24)"
ADMIN_HASH="$(doveadm pw -s SHA512-CRYPT -p "${ADMIN_PASS}" | tail -n 1)"
echo "admin@xdexboard.com:${ADMIN_HASH}" > "${PASSWD_ADMIN}"
chown root:dovecot "${PASSWD_ADMIN}"
chmod 640 "${PASSWD_ADMIN}"
systemctl restart dovecot
echo "${ADMIN_PASS}" | doveadm auth test admin@xdexboard.com
echo "PASSWORD:${ADMIN_PASS}"

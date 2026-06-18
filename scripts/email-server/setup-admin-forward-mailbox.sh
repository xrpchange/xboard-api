#!/usr/bin/env bash
# admin@xdexboard.com — full IMAP mailbox (Outlook) + BCC copy for mobile push.
# Run on mail host as root. Does NOT open SMTP relay (sender_login_maps + reject_sender_login_mismatch).
set -euo pipefail

PUSH_BCC_MAP="/etc/postfix/operator-push-bcc.map"
PUSH_DEST="${1:-tehnograny@yandex.ru}"
MAILBOX_MAPS="/etc/postfix/virtual-mailbox-maps"
PASSWD_ADMIN="/etc/dovecot/passwd.admin"
DOVECOT_SNIPPET="/etc/dovecot/conf.d/05-admin-passwd.conf"
MAILDIR="/var/mail/vhosts/xdexboard.com/admin"
FORWARD_ONLY_MAP="/etc/postfix/operator-forward.map"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root (sudo $0 [push-bcc@domain])" >&2
  exit 1
fi

install -d -o vmail -g vmail -m 0750 "${MAILDIR}"/{cur,new,tmp}
install -d -o vmail -g vmail -m 0750 "${MAILDIR}/.Trash"/{cur,new,tmp} 2>/dev/null || true

grep -q '^admin@xdexboard.com' "${MAILBOX_MAPS}" 2>/dev/null || \
  echo -e 'admin@xdexboard.com\txdexboard.com/admin/' >> "${MAILBOX_MAPS}"
postmap "hash:${MAILBOX_MAPS}"

# Remove forward-only alias if present (would skip local mailbox).
if [[ -f "${FORWARD_ONLY_MAP}" ]]; then
  sed -i '/^admin@xdexboard.com/d' "${FORWARD_ONLY_MAP}"
  postmap "hash:${FORWARD_ONLY_MAP}" 2>/dev/null || true
fi

cat >"${PUSH_BCC_MAP}" <<EOF
# BCC copy when mail arrives at admin@ (Outlook keeps primary copy in Maildir).
# Change address, then: postmap hash:${PUSH_BCC_MAP} && postfix reload
admin@xdexboard.com	${PUSH_DEST}
EOF
postmap "hash:${PUSH_BCC_MAP}"

if ! grep -q 'operator-push-bcc.map' /etc/postfix/main.cf; then
  echo 'recipient_bcc_maps = hash:/etc/postfix/operator-push-bcc.map' >> /etc/postfix/main.cf
fi

grep -q '^admin@xdexboard.com' /etc/postfix/exempt_recipients 2>/dev/null || \
  echo -e 'admin@xdexboard.com\tOK' >> /etc/postfix/exempt_recipients
postmap hash:/etc/postfix/exempt_recipients

grep -q '^admin@xdexboard.com' /etc/postfix/sender_login_maps 2>/dev/null || \
  echo -e 'admin@xdexboard.com\tadmin@xdexboard.com' >> /etc/postfix/sender_login_maps
postmap hash:/etc/postfix/sender_login_maps

if [[ ! -f "${PASSWD_ADMIN}" ]]; then
  ADMIN_PASS="$(openssl rand -base64 24)"
  ADMIN_HASH="$(doveadm pw -s SHA512-CRYPT -p "${ADMIN_PASS}" | tail -n 1)"
  install -m 0640 -o root -g dovecot /dev/stdin "${PASSWD_ADMIN}" <<<"admin@xdexboard.com:${ADMIN_HASH}"
  echo "New SMTP/IMAP password (save in password manager):"
  echo "${ADMIN_PASS}"
else
  echo "Keeping existing ${PASSWD_ADMIN} (not rotated)."
fi

install -m 0644 /dev/stdin "${DOVECOT_SNIPPET}" <<'EOF'
# admin@xdexboard.com — operator mailbox (IMAP/Outlook); not lot_mailboxes.
passdb {
  driver = passwd-file
  args = username_format=%u /etc/dovecot/passwd.admin
}
userdb {
  driver = static
  args = uid=vmail gid=vmail home=/var/mail/vhosts/xdexboard.com/admin mail=maildir:~/
}
EOF

systemctl restart dovecot
postfix reload

echo "admin@xdexboard.com: Maildir + IMAP (mail.xdexboard.com), BCC push copy → ${PUSH_DEST}"
echo "Change push address: edit ${PUSH_BCC_MAP}, postmap, postfix reload"
echo "Outlook: IMAP mail.xdexboard.com:993 SSL, user admin@xdexboard.com"

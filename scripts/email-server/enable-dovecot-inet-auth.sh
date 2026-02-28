#!/bin/bash
# Enable Dovecot auth via TCP (port 12345) so Postfix can use inet:127.0.0.1:12345
# when the unix socket /var/spool/postfix/private/auth is not created.
# Run on mail server with sudo. See docs/LOT_MAILBOX_AND_IMAP_CHAT.md

set -e
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo"; exit 1; }
MASTER_CONF=/etc/dovecot/conf.d/10-master.conf
POSTFIX_MASTER=/etc/postfix/master.cf

# 1) Backup
cp -a "$MASTER_CONF" "${MASTER_CONF}.bak.inet"
cp -a "$POSTFIX_MASTER" "${POSTFIX_MASTER}.bak.inet"

# 2) Add inet_listener to service auth (after the "  }" that closes unix_listener postfix block)
if ! grep -q 'port = 12345' "$MASTER_CONF"; then
  INET_BLOCK=$(mktemp)
  cat > "$INET_BLOCK" << 'INETEOF'
  # Postfix SASL via TCP (workaround when unix socket not created)
  inet_listener auth {
    port = 12345
  }
INETEOF
  LINE=$(awk '/unix_listener \/var\/spool\/postfix\/private\/auth/,/^  }$/{ if (/^  }$/) { print NR; exit } }' "$MASTER_CONF")
  [ -n "$LINE" ] && sed -i "${LINE}r $INET_BLOCK" "$MASTER_CONF"
  rm -f "$INET_BLOCK"
  echo "Added inet_listener port 12345 to $MASTER_CONF"
else
  echo "inet_listener 12345 already present in $MASTER_CONF"
fi

# 3) Postfix: use inet instead of unix socket
sed -i 's|smtpd_sasl_path=private/auth|smtpd_sasl_path=inet:127.0.0.1:12345|g' "$POSTFIX_MASTER"
echo "Updated Postfix master.cf to use inet:127.0.0.1:12345"

# 4) Restart
systemctl restart dovecot
sleep 2
systemctl restart postfix
echo "Restarted dovecot and postfix."
ss -tlnp | grep -E '12345|587|465' || true

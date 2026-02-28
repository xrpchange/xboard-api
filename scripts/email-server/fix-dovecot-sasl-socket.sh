#!/bin/bash
# Fix Dovecot so it creates the Postfix SASL socket at /var/spool/postfix/private/auth.
# Run on the mail server (e.g. mail.xdexboard.com) with sudo.
# See docs/LOT_MAILBOX_AND_IMAP_CHAT.md "Устранение неполадок SMTP".

set -e
DOVECOT_CONF="${DOVECOT_CONF:-/etc/dovecot/dovecot.conf}"
MASTER_CONF="${MASTER_CONF:-/etc/dovecot/conf.d/10-master.conf}"

echo "Fixing Dovecot for Postfix SASL socket..."

# 0. Ensure main config includes conf.d (otherwise 10-master.conf is never loaded)
if [ -f "$DOVECOT_CONF" ] && ! grep -q 'include.*conf\.d' "$DOVECOT_CONF" 2>/dev/null; then
  echo "Adding !include_try conf.d/*.conf to $DOVECOT_CONF"
  sudo bash -c "echo '' >> $DOVECOT_CONF"
  sudo bash -c "echo '# Include config snippets (required for 10-master.conf Postfix auth socket)' >> $DOVECOT_CONF"
  sudo bash -c "echo '!include_try conf.d/*.conf' >> $DOVECOT_CONF"
fi

# 1. Replace obsolete ssl_protocols with ssl_min_protocol (Dovecot 2.3.9+)
if grep -q 'ssl_protocols = !SSLv2 !SSLv3' "$DOVECOT_CONF" 2>/dev/null; then
  sudo sed -i.bak 's/ssl_protocols = !SSLv2 !SSLv3/ssl_min_protocol = TLSv1.2/g' "$DOVECOT_CONF"
  echo "Updated $DOVECOT_CONF (backup: ${DOVECOT_CONF}.bak)"
else
  echo "No ssl_protocols to replace in $DOVECOT_CONF"
fi

# 2. Remove chroot in service auth so unix_listener absolute path is used (Grok: chroot makes path relative)
if [ -f "$MASTER_CONF" ]; then
  if grep -q 'chroot' "$MASTER_CONF" 2>/dev/null; then
    sudo sed -i.bak -E 's/^(\s*)(chroot\s*=.*)$/\1# \2/' "$MASTER_CONF"
    echo "Commented out chroot in $MASTER_CONF (backup: ${MASTER_CONF}.bak)"
  else
    echo "No chroot in $MASTER_CONF"
  fi
fi

# 3. Ensure dovecot can write to postfix private directory
PRIVATE_DIR="/var/spool/postfix/private"
if [ -d "$PRIVATE_DIR" ]; then
  sudo chown postfix:postfix "$PRIVATE_DIR"
  sudo chmod 730 "$PRIVATE_DIR"
  sudo usermod -a -G postfix dovecot 2>/dev/null || true
  echo "Set $PRIVATE_DIR postfix:postfix 730, dovecot in group postfix"
fi

# 4. Restart Dovecot
echo "Restarting Dovecot..."
sudo systemctl restart dovecot

sleep 2
if [ -S "$PRIVATE_DIR/auth" ]; then
  echo "OK: Socket $PRIVATE_DIR/auth exists."
  ls -la "$PRIVATE_DIR/auth"
else
  echo "WARN: Socket $PRIVATE_DIR/auth still missing. Check /var/log/dovecot.log and 10-master.conf unix_listener."
fi

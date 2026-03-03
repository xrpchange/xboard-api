#!/bin/bash
# One-time fix: Dovecot lot mailboxes must use maildir:~/ (not global mbox).
# Run on mail server: sudo bash fix-dovecot-mail-location.sh

CF="/etc/dovecot/dovecot-sql-lot.conf"
# Fix broken value (missing leading quote) or add mail field
if grep -q " maildir:" "$CF" 2>/dev/null; then
  sudo sed -i "s/ maildir:~\\/' AS/ '\''maildir:~\/'\'' AS/" "$CF"
elif ! grep -q "AS mail FROM" "$CF" 2>/dev/null; then
  sudo sed -i "s/AS home FROM lot_mailboxes/AS home, '\''maildir:~\/'\'' AS mail FROM lot_mailboxes/" "$CF"
fi
grep user_query "$CF" || true
sudo systemctl restart dovecot
echo "Dovecot restarted. Reconnect IMAP (Canary) and sync."

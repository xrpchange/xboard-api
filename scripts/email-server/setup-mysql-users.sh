#!/bin/bash
# Create MySQL users for Postfix and Dovecot (lot_mailboxes), then run setup-lot-mailboxes
# Run once on mail server with sudo
# Optional: LOT_POSTFIX_DB_PASS=... LOT_DOVECOT_DB_PASS=... to use your own passwords
set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PF_PASS="${LOT_POSTFIX_DB_PASS:-$(openssl rand -base64 16)}"
DC_PASS="${LOT_DOVECOT_DB_PASS:-$(openssl rand -base64 16)}"
echo "Creating MySQL users..."
sudo mysql -e "
CREATE USER IF NOT EXISTS 'postfix'@'localhost' IDENTIFIED BY '$PF_PASS';
GRANT SELECT ON xboard.lot_mailboxes TO 'postfix'@'localhost';
CREATE USER IF NOT EXISTS 'dovecot'@'localhost' IDENTIFIED BY '$DC_PASS';
GRANT SELECT ON xboard.lot_mailboxes TO 'dovecot'@'localhost';
FLUSH PRIVILEGES;
"
echo "Running setup-lot-mailboxes.sh..."
export LOT_POSTFIX_DB_PASS="$PF_PASS"
export LOT_DOVECOT_DB_PASS="$DC_PASS"
sudo -E "$SCRIPT_DIR/setup-lot-mailboxes.sh"

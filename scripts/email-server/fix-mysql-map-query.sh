#!/bin/bash
# One-time fix: Postfix virtual_mailbox_maps MySQL query must return path RELATIVE to virtual_mailbox_base.
# The map was returning /var/mail/vhosts/... so Postfix concatenated base+result = duplicate path.

CF="/etc/postfix/mysql-virtual-mailbox-lot.cf"
NEW_QUERY="query = SELECT CONCAT(domain, '/', local_part, '/') FROM lot_mailboxes WHERE CONCAT(local_part, '@', domain) = '%s' LIMIT 1"

sudo sed -i "s|^query = .*|$NEW_QUERY|" "$CF" && grep "^query" "$CF" && sudo systemctl reload postfix && echo "Postfix reloaded OK"

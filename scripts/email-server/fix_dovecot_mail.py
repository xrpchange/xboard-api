#!/usr/bin/env python3
"""Fix dovecot-sql-lot.conf: ensure user_query has 'maildir:~/' AS mail (for IMAP to show Maildir)."""
import sys
path = "/etc/dovecot/dovecot-sql-lot.conf"
with open(path) as f:
    content = f.read()
# Fix broken: ",  maildir:~/' AS" -> ", 'maildir:~/' AS"
content = content.replace(",  maildir:~/' AS", ", 'maildir:~/' AS")
# Or add if missing
if "AS mail FROM" not in content:
    content = content.replace(
        "AS home FROM lot_mailboxes",
        "AS home, 'maildir:~/' AS mail FROM lot_mailboxes"
    )
with open(path, "w") as f:
    f.write(content)
print("OK", file=sys.stderr)

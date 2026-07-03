# Checking Postfix for "Start deal" emails

When a buyer taps "Start deal", the API sends an email to the seller via Laravel Mail (→ Postfix). If the letter does not arrive, check the following.

## 1. API logs (Laravel)

On the API server, check that the send was attempted and whether it succeeded or threw:

```bash
# Recent Laravel log (path may vary: storage/logs/laravel.log or via journalctl)
grep -E "sendMessageToSeller|exchange-lots.*send" /path/to/xboard-api/storage/logs/laravel.log | tail -50

# If using systemd for PHP/Laravel
journalctl -u your-php-fpm-or-api-service -n 200 --no-pager | grep -E "sendMessageToSeller|exchange-lots"
```

You should see one of:

- `[exchange-lots] sendMessageToSeller: attempt` — request reached the controller (lot_id, seller_email).
- `[exchange-lots] sendMessageToSeller: sending mail` — we are calling Mail::raw (to, from, subject).
- `[exchange-lots] sendMessageToSeller: mail sent successfully` — Laravel reports success (handed off to mailer).
- `[exchange-lots] sendMessageToSeller: failed to send mail` — exception (error in log).
- `[exchange-lots] sendMessageToSeller: no seller mailbox` — no mailbox for lot wallet/currency.

If you see "mail sent successfully", the failure is after Laravel (e.g. Postfix or delivery).

## 2. Postfix logs (on mail server)

SSH to the mail server and inspect Postfix:

```bash
ssh root@xrpchange.site

# Recent mail log (typical paths)
sudo tail -200 /var/log/mail.log
# or
sudo journalctl -u postfix -n 200 --no-pager

# Only errors
sudo grep -E "warning|error|reject|defer" /var/log/mail.log | tail -50

# Search by recipient (replace with actual seller mailbox address from API log)
sudo grep "to=<SELLER_EMAIL>" /var/log/mail.log | tail -20

# Search by queue id (if you have it from a previous grep)
sudo grep "QUEUE_ID" /var/log/mail.log
```

Interpretation:

- **No lines for that `to=` address** — Postfix never received the message; check API host (different server?) and Laravel mail config (e.g. `MAIL_MAILER`, `SMTP` vs `sendmail`).
- **status=sent** — Postfix accepted and delivered (or handed to next hop); if the user still has no letter, check spam, next-hop logs, or wrong address.
- **status=deferred / warning / reject** — read the reason in the same or next line (e.g. relay denied, DNS, recipient unknown).

## 3. Laravel mail config

On the API server, ensure mail is sent via the host that runs Postfix (or the same host):

- If API and Postfix are on the same machine: `MAIL_MAILER=log` only logs; use `sendmail` or `smtp` to actually send.
- If API uses SMTP to server@xdexboard.com: check `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_ENCRYPTION` and that Postfix (or the MTA on that host) accepts submissions (e.g. submission port, auth).

After changing config, restart the PHP/queue workers so the new config is loaded.

## 4. Typical Postfix errors (lot mailboxes)

### 4.1 `bad string length 0 < 1: dbname =` (smtpd fatal)

**Symptom:** In `/var/log/mail.log`: `fatal: /etc/postfix/mysql-virtual-mailbox-lot.cf: bad string length 0 < 1: dbname =`. Postfix smtpd fails to start or throttles; SMTP connections time out (e.g. API gets "Connection to mail.xdexboard.com:587 timed out").

**Cause:** The `dbname` parameter in `/etc/postfix/mysql-virtual-mailbox-lot.cf` is missing or empty (e.g. after a manual edit or a script that only changed the `query` line).

**Fix:** Add or restore the line in `/etc/postfix/mysql-virtual-mailbox-lot.cf`:
```bash
dbname = xboard
```
Then reload Postfix: `sudo systemctl reload postfix`.

### 4.2 MySQL lookup: `Can't connect to local MySQL server through socket` / `451 4.3.0 Temporary lookup failure`

**Symptom:** In `/var/log/mail.log`: `connect to mysql server localhost: Can't connect to local MySQL server through socket '/var/run/mysqld/mysqld.sock' (2)` and `mysql:.../mysql-virtual-mailbox-lot.cf lookup error`. RCPT TO returns `451 4.3.0 Temporary lookup failure`. MySQL is running and reachable (e.g. via phpMyAdmin).

**Cause:** Postfix **smtpd** runs in **chroot** (see `master.cf`: `chroot = y`). Inside the chroot the process cannot see the MySQL Unix socket at `/var/run/mysqld/mysqld.sock`. Using `hosts = localhost` forces the MySQL client to use the socket.

**Fix:** In `/etc/postfix/mysql-virtual-mailbox-lot.cf` use TCP instead of the socket:
```bash
hosts = 127.0.0.1
```
(Keep `user`, `password`, `dbname`, and `query` as they are.) Then reload Postfix: `sudo systemctl reload postfix`. The setup script `scripts/email-server/setup-lot-mailboxes.sh` uses `127.0.0.1` by default for this reason.

### 4.3 Maildir: `Permission denied` / duplicate path (e.g. `/var/mail/vhosts//var/mail/vhosts/...`)

**Symptom:** In `/var/log/mail.log`: delivery failed, path like `/var/mail/vhosts//var/mail/vhosts/domain/...` or "create maildir file ... Permission denied".

**Cause:** The MySQL map for `virtual_mailbox_maps` must return a path **relative** to `virtual_mailbox_base` (e.g. `/var/mail/vhosts`). If the query returns the full path including the base, Postfix concatenates base + result and gets a wrong path.

**Fix:** In `/etc/postfix/mysql-virtual-mailbox-lot.cf` the query must return only `domain/local_part/`:
```text
query = SELECT CONCAT(domain, '/', local_part, '/') FROM lot_mailboxes WHERE CONCAT(local_part, '@', domain) = '%s' LIMIT 1
```
Not: `SELECT CONCAT('/var/mail/vhosts/', domain, '/', local_part, '/') ...`  
Then reload Postfix: `sudo systemctl reload postfix`.

### 4.4 IMAP client (Canary, etc.) shows empty Inbox for lot mailbox

**Symptom:** Postfix delivers to maildir (`status=sent (delivered to maildir)` in `/var/log/mail.log`), files appear in `/var/mail/vhosts/domain/local_part/new/` and `cur/`, but the IMAP client does not show any messages for that account.

**Cause A — SQL userdb has no `mail`:** Dovecot uses a global `mail_location` (often `mbox:~/mail:INBOX=/var/mail/%u`). For users from the lot_mailboxes SQL, the actual messages are in a **Maildir** at the user's `home` path. Without overriding the location per user, Dovecot looks at the wrong place.

**Fix A:** In `/etc/dovecot/dovecot-sql-lot.conf`, the `user_query` must return a `mail` field so Dovecot uses Maildir at home:
```text
user_query = SELECT CONCAT(local_part, '@', domain) AS user, 'vmail' AS uid, 'vmail' AS gid, CONCAT('/var/mail/vhosts/', domain, '/', local_part) AS home, 'maildir:~/' AS mail FROM lot_mailboxes WHERE local_part = '%n' AND domain = '%d'
```
Then restart Dovecot: `sudo systemctl restart dovecot`.

**Cause B — Static userdb is first and has no `mail`:** In `/etc/dovecot/dovecot.conf` (or included config) the **first** userdb may be a static one, e.g. `userdb { driver = static; args = uid=vmail gid=vmail home=/var/mail/vhosts/%d/%n }`. It applies to all virtual users and does not set `mail=`, so Dovecot falls back to the global `mail_location` (mbox). The lot_mailboxes SQL userdb is queried later, but the first userdb already supplied `home` and no `mail`, so the effective location stays mbox.

**Fix B:** Add `mail=maildir:~/` to that **static** userdb's `args` in `/etc/dovecot/dovecot.conf`:
```text
args = uid=vmail gid=vmail home=/var/mail/vhosts/%d/%n mail=maildir:~/
```
Then restart Dovecot: `sudo systemctl restart dovecot`. Reconnect the IMAP client (e.g. remove and re-add the account in Canary/Outlook) and sync. See also `docs/LOT_MAILBOX_AND_IMAP_CHAT.md` (Dovecot config for lot mailboxes).

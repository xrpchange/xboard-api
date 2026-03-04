# Checking logs: deal message status (delivered / read)

When the buyer is on the "Deal in progress" screen, the app calls `GET /exchange-lots/{lot}/deal-message-status`. The API determines **delivered** (message in seller's Maildir) and **read** (message in `cur/` with Seen flag) by scanning the seller's Maildir. If "read" never appears, use the logs below to debug.

## 1. xboard-api (Laravel) — project logs

**Location:** `storage/logs/laravel.log` (relative to xboard-api root).

Relevant entries use the tag `[lot-mailbox] getDealMessageStatus`:

```bash
# On the API server (path to project may vary)
cd /path/to/xboard-api
grep 'getDealMessageStatus' storage/logs/laravel.log | tail -50

# Or with context
grep -E 'lot-mailbox|deal-message-status' storage/logs/laravel.log | tail -80
```

What to look for:

- **`getDealMessageStatus: maildir_base not configured`** — `LOT_MAILBOX_MAILDIR_BASE` is not set in `.env`; API cannot scan Maildir.
- **`getDealMessageStatus: no matching message`** — no file in `new/` or `cur/` contained the lot id (in raw content or decoded Subject). Check `path` in the log (seller's Maildir path).
- **`getDealMessageStatus`** (success) — shows `file`, `in_cur`, `seen`, `read_result`:
  - `in_cur` = true and `seen` = true → `read_result` = true (message considered read).
  - If `read_result` is false while the seller has opened the letter, the filename may not have the `:2,S` (or `S` in flags) — see Dovecot Maildir format; path and filename are in the log.

Ensure `LOG_LEVEL` in `.env` is at least `info` so these lines are written (they are logged with `Log::info()`).

## 2. Mail server (Postfix / Dovecot) — SSH

**Access:** `ssh root@xrpchange.site`

Postfix is used for delivery; Dovecot provides IMAP and updates Maildir (moves messages from `new/` to `cur/` and sets the Seen flag when the user reads the message).

### Postfix (delivery)

```bash
ssh root@xrpchange.site

# Recent mail log
sudo tail -200 /var/log/mail.log
# or
sudo journalctl -u postfix -n 200 --no-pager

# By recipient (seller mailbox from API log)
sudo grep "to=<SELLER_EMAIL>" /var/log/mail.log | tail -20
```

### Dovecot (IMAP / Maildir flags)

When the seller opens the email in an IMAP client, Dovecot renames the file (e.g. from `new/` to `cur/` and adds `:2,S` to the filename). If "read" is never detected:

```bash
ssh root@xrpchange.site

# Dovecot logs
sudo journalctl -u dovecot -n 300 --no-pager

# Check Maildir on disk (path from API log: path in getDealMessageStatus)
# Example: /var/mail/vhosts/domain/local_part/cur/
ls -la /var/mail/vhosts/<domain>/<local_part>/cur/
ls -la /var/mail/vhosts/<domain>/<local_part>/new/
```

Filenames in `cur/` should end with `:2,FLAGS` (e.g. `:2,S` for Seen). If the file is in `cur/` but the name does not contain `S` in the flags part, the IMAP client may not be setting \Seen, or Dovecot may use a different format.

## 3. Quick reference

| What you check | Where |
|----------------|--------|
| API: deal-message-status logic, path, file, seen, read_result | xboard-api: `storage/logs/laravel.log`, grep `getDealMessageStatus` or `lot-mailbox` |
| Postfix: delivery to seller mailbox | SSH `root@xrpchange.site` → `/var/log/mail.log` or `journalctl -u postfix` |
| Dovecot: IMAP and Maildir | SSH `root@xrpchange.site` → `journalctl -u dovecot`, and inspect `cur/` / `new/` under Maildir path |

See also: `docs/CHECK_POSTFIX_DEAL_MESSAGE.md` (sending the deal email), `docs/LOT_MAILBOX_AND_IMAP_CHAT.md` (Maildir and Dovecot config).

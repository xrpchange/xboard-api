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

## 2. Postfix logs (on server@xrpchange.site)

SSH to the mail server and inspect Postfix:

```bash
ssh server@xrpchange.site

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
- If API uses SMTP to server@xrpchange.site: check `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_ENCRYPTION` and that Postfix (or the MTA on that host) accepts submissions (e.g. submission port, auth).

After changing config, restart the PHP/queue workers so the new config is loaded.

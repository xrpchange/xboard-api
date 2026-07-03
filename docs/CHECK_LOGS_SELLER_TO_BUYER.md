# Checking logs: message from seller to buyer

When the seller sends a message to the buyer, the API calls `Mail::raw()` to the buyer's mailbox address (`buyer_lot_email`). If the message does not arrive, check the following.

## 1. SSH access

```bash
ssh server@xdexboard.com
```

- Hostname may be `supersrv` or `mail` depending on the host.
- **Mail log** is on the host where Postfix runs (e.g. `sudo tail -100 /var/log/mail.log`).
- **Laravel log** is on the host where the xboard-api PHP app runs; path is typically `storage/logs/laravel.log` relative to the project root. On some setups `/opt/xboard-api/` contains only scripts — the full Laravel app may be elsewhere (another path or another server).

## 2. API logs (Laravel) — sendMessageToBuyer

On the **API server** (where the Laravel app runs), run:

```bash
# Path to project root (adjust if your deployment differs)
API_ROOT=/path/to/xboard-api

grep -E "sendMessageToBuyer|send-message-to-buyer" "$API_ROOT/storage/logs/laravel.log" | tail -80
```

What to look for:

- **`sendMessageToBuyer: start`** — request reached the controller (lot_id, lot_currency).
- **`sendMessageToBuyer: not_seller`** — 403: requester's token did not match the seller. The API has two fallbacks: (1) seller mailbox local_part ends with _normalized(publicPart); (2) normalized(publicPart) equals normalized(lot->wallet_address). If you still see not_seller, the token may be for a different wallet.
- **`sendMessageToBuyer: sending mail`** — seller check passed; about to call Mail::raw (to, subject).
- **`sendMessageToBuyer: failed to send mail`** — exception (e.g. SMTP connection timeout). Check `error` and `trace` in the log.
- **`sendMessageToBuyer: mail sent`** — Laravel handed the message to the mailer; then check Postfix.

If you see **not_seller**, the client is sending a token that resolves to a different mailbox than the lot's seller (e.g. `usdt_...` vs `usdt_tron_...`). The API has a fallback that matches by normalized wallet address; ensure the latest code is deployed.

## 3. Postfix logs (mail server)

On the host where Postfix runs (e.g. after `ssh server@xdexboard.com`):

```bash
# Recent deliveries
sudo tail -100 /var/log/mail.log

# Only deliveries to xdexboard.com (lot mailboxes)
sudo grep "to=.*@xdexboard.com" /var/log/mail.log | tail -30

# By specific buyer mailbox (replace with actual buyer_lot_email from API)
sudo grep "to=<BUYER_MAILBOX_EMAIL>" /var/log/mail.log | tail -20
```

- **No line for that `to=`** — Postfix never received the message; the failure is before Postfix (API not sending or wrong host/port).
- **status=sent (delivered to maildir)** — message was delivered to the buyer's Maildir; the buyer should see it in the deal chat (IMAP).
- **status=deferred / reject** — read the reason in the same or next line.

## 4. Quick reference

| Check | Where | Command |
|-------|--------|---------|
| API: sendMessageToBuyer (start, not_seller, failed, mail sent) | API server: Laravel project root | `grep -E "sendMessageToBuyer|send-message-to-buyer" storage/logs/laravel.log \| tail -80` |
| Postfix: delivery to buyer mailbox | Mail server | `sudo grep "to=<...@xdexboard.com>" /var/log/mail.log \| tail -20` |

See also: `docs/CHECK_POSTFIX_DEAL_MESSAGE.md` (seller notification / start_deal).

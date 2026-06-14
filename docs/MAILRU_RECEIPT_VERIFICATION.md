# Mail.ru: sandbox receipts and payment verification

Operational notes for **Mail.ru** (`@mail.ru`, `@inbox.ru`, `@list.ru`, `@bk.ru`, `@internet.ru`, …) when verifying bank receipts (DKIM + PDF) in XBoard.

## Symptom

- Client shows **DKIM valid**, sender **XBoard Sandbox Receipt Bank** / `noreply@xdexboard.com` — looks correct.
- API returns **`Email is invalid: no PDF attachments found`** (Laravel: `verifyEmailContent: invalid receipt, no PDF attachments`).
- Postfix log shows a small message (~1–2 KB), not a typical sandbox PDF receipt (~6–7 KB+).

This is **not** a DKIM bug on our side. The raw EML fetched over IMAP often **no longer contains the PDF part**.

## Known Mail.ru behaviour (self / same mailbox)

Mail.ru may **alter or strip MIME parts** (including PDF attachments) in scenarios that look like **“sending to yourself”** or heavy internal routing, for example:

- **`buyer_notification_email` / `seller_notification_email`** is `@mail.ru`, and the same address is used in **Settings → Email (IMAP)** to read and verify the receipt.
- Test or deal mail repeatedly lands in the **same Mail.ru inbox** the app reads (sandbox receipt from `noreply@xdexboard.com` → user `@mail.ru` → verify via IMAP on that account).

**Workaround confirmed in production testing:** use another provider for notification email and/or IMAP (Yandex, Gmail, iCloud, …). Verification works with the same API and the same sandbox flow.

## What we do on the server

- Sandbox PDF receipt is sent from **`noreply@xdexboard.com`** with DKIM on **`xdexboard.com`**, header `X-XBoard-Sandbox-Test-Bank-Receipt: 1`, attachment `receipt-lot-{id}.pdf`. See **`docs/SANDBOX_VIRTUAL_BANK.md`**.
- `POST /api/v1/payments/verify-email-content` only extracts PDFs from the **raw EML the client sends**. If Mail.ru removed the attachment before IMAP `RFC822` fetch, the API cannot recover it.

## Mail.ru liaison (whitelist / deliverability)

For stable sandbox and production testing with Mail.ru users, **contact Mail.ru** (postmaster / business support) and ask them **not to strip or rewrite** transactional mail from XBoard:

| Item | Value |
|------|--------|
| From | `noreply@xdexboard.com` |
| Sending host | `mail.xdexboard.com` (Postfix, DKIM d=xdexboard.com) |
| Mail type | Transactional: deal chat, payment details, **sandbox PDF receipts** (not bulk marketing) |
| Ask | Full MIME preserved on IMAP (`RFC822` includes PDF); no “self-send” / anti-abuse stripping for this stream |

Keep SPF/DKIM/DMARC aligned — **`docs/MAIL_SPF_DKIM_DMARC.md`**.

## Client / support checklist

1. Confirm the user opened **`Sandbox test receipt PDF — Lot #…`** (not another `noreply` message: chat wake, payment details only).
2. If notification + IMAP are both **@mail.ru** and PDF is missing → suggest **another mailbox** for deal notifications or for IMAP verification.
3. Optional: export **`.eml`** from a client that still has the PDF (if Mail.ru web UI shows the attachment) and upload — only if MIME is intact.
4. Server log (warning level): `verifyEmailContent: invalid receipt, no PDF attachments`.

## Related

- **`docs/SANDBOX_VIRTUAL_BANK.md`** — when the PDF receipt is sent and to which notification email.
- **`docs/USER_EMAIL_VERIFICATION_GUIDE.md`** — verification methods (IMAP, upload `.eml`).
- **xboard-client:** `docs/EMAIL_VERIFICATION_FLOW.md` — client sends EML only; no mailbox credentials on API.

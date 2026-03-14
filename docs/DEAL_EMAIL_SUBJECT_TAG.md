# Deal email tag (JSON) — X-XBoard-Deal-Tag header

System emails sent to the buyer during a deal (e.g. payment details from the seller) carry a structured **tag** as JSON. The client parses this tag to render the chat message and to perform API calls (e.g. reservation-by-transaction).

- **From:** `noreply@<domain>` (same as other system notifications).
- **To:** Buyer's lot mailbox (`buyer_lot_email`).
- **Subject:** Human-readable (e.g. "Payment details — Lot #42"). Not used for machine parsing.
- **X-XBoard-Deal-Tag:** Custom header containing the tag as a single-line JSON string (no newlines). The client reads this header when available (e.g. from IMAP or from the chat/mailbox API) to detect deal-related system messages.

---

## Tag format

The subject line is **valid JSON**. Common fields:

| Field       | Type   | Required | Description |
|------------|--------|----------|-------------|
| `t`        | string | yes      | Message type (see below). |
| `lot_id`   | int    | yes      | Exchange lot id (for threading and UI). |
| `tx_id`    | int    | no       | API transaction id (set when transaction exists). |
| `crypto`   | string | cond.    | Crypto type (e.g. `xmr`, `xrp`) for crypto payment. |
| `address`  | string | cond.    | Crypto address for buyer to send funds to. |
| `bank_swift`| string | cond.    | Bank SWIFT code (same as in lot search; preferred over bank_id). |
| `bank_id`  | int    | cond.    | Bank id if swift not used (same as in lot search). |
| `bank_data`| string | no       | Free-form bank transfer details (card, phone, full details). |

- For **crypto** payment: `crypto` and `address` are set; the address is stored in `transactions.crypto_address`.
- For **fiat/bank** payment: `bank_id` is taken from the lot (buyer-selected bank in `exchange_lots`); `bank_data` is the free-form transfer details. These are stored in `transactions.bank_id` and `transactions.bank_details`. No bank selection in the payment-details dialog — the bank is already on the lot.

Other server-set fields may be added (e.g. `amount`, `currency`) in the same JSON.

---

## Message types (`t`)

| Value               | Description |
|---------------------|-------------|
| `payment_details`   | Seller sent payment instructions: either crypto address or bank + bank_data. Buyer uses this to pay and can call Core `reservation-by-transaction` with `tx_id`, `crypto`, `address` when checking reservation. |
| `buyer_exited`      | Buyer cancelled the deal (e.g. via buyer-cancel-deal). Email is sent **to the seller**. Tag includes `lot_id`. The seller app, when it sees a message with this tag in the deal mailbox, unblocks the "Exit deal" button so the seller can close the deal. |

Other types may be added without changing the format.

---

## Examples

**Crypto (XMR address):**
```json
{"t":"payment_details","lot_id":42,"tx_id":101,"crypto":"xmr","address":"48edf..."}
```

**Fiat (bank SWIFT + free-form data):**
```json
{"t":"payment_details","lot_id":42,"tx_id":102,"bank_swift":"SABRRUMM","bank_data":"Card *1234, phone +7..."}
```

**Fiat (bank_id when swift not used):**
```json
{"t":"payment_details","lot_id":42,"tx_id":103,"bank_id":5,"bank_data":"Full account details..."}
```

**Buyer exited (email to seller):**
```json
{"t":"buyer_exited","lot_id":42}
```

---

## Client behavior

1. **Fetch email** (IMAP) to buyer's deal mailbox.
2. **Parse subject** as JSON. If parsing fails, treat as plain message (no tag).
3. **If `t === "payment_details"`:**
   - Show in chat: for crypto — address (and crypto); for fiat — bank (swift or id) + bank_data; plus any free text from body.
   - When `tx_id` is present, buyer can call Core open endpoint `GET/POST reservation-by-transaction` with `transaction_id=tx_id`, `crypto_type=crypto`, `wallet_address=address` to get reserved amount.
4. **Tag location:** The tag is sent in the **X-XBoard-Deal-Tag** header so the subject can stay human-readable. Client must read `X-XBoard-Deal-Tag` when displaying deal messages (e.g. from IMAP headers or from the mailbox/chat API that exposes headers). If the header is missing, the message is treated as a plain user message. The email body contains a human-readable summary for the user.

---

## Notes

- **Bank identification:** Use the same as in lot search: prefer `bank_swift`, fallback `bank_id`, so that admins and scripts can identify the bank from the tag.
- **bank_data:** Arbitrary text (card, phone, full requisites); no structure required.
- **Encoding:** JSON in subject must be one line; avoid unescaped newlines or quotes in string values.

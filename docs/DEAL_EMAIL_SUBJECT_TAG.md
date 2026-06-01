# Deal email tag (JSON) — X-XBoard-Deal-Tag header

System emails sent to the buyer during a deal (e.g. payment details from the seller) carry a structured **tag** as JSON. The client parses this tag to render the chat message and to perform API calls (e.g. reservation-by-transaction).

- **From:** `noreply@<domain>` (same as other system notifications).
- **To:** Buyer's lot mailbox (`buyer_lot_email`).
- **Subject:** Human-readable (e.g. "Payment details — Lot #42"). Not used for machine parsing.
- **X-XBoard-Deal-Tag:** Custom header containing the tag as a single-line JSON string (no newlines). The client reads this header when available (e.g. from IMAP or from the chat/mailbox API) to detect deal-related system messages.
- **Delivery priority:** All transactional mail uses MIME **priority 1** (highest): `App\Mail\HighDeliveryPriority::apply()` on Laravel `Mail` messages, and `HighDeliveryPriority::injectIntoRawMime()` on raw RFC822 when using Postmark/Resend/Mailgun HTTP APIs (no bulk/marketing traffic).

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
| `selected_amount` | number | no | Buyer-selected amount (e.g. for `start_deal`). |
| `buyer_locale`    | string | no | Buyer UI locale (e.g. `en`, `ru`) for `start_deal`. |

- For **crypto** payment: `crypto` and `address` are set; the address is stored in `transactions.crypto_address`.
- For **fiat/bank** payment: `bank_id` is taken from the lot (buyer-selected bank in `exchange_lots`); `bank_data` is the free-form transfer details. These are stored in `transactions.bank_id` and `transactions.bank_details`. No bank selection in the payment-details dialog — the bank is already on the lot.

Other server-set fields may be added (e.g. `amount`, `currency`) in the same JSON.

---

## Message types (`t`)

| Value               | Description |
|---------------------|-------------|
| `start_deal`        | Buyer opened the deal (e.g. "Start deal — buyer opened the deal window"). Email is sent **to the seller**. Tag includes `lot_id`; may include `selected_amount` (buyer-selected amount) and `buyer_locale` (e.g. `en`, `ru`) for analytics and UI. |
| `payment_details`   | Seller sent payment instructions for the **first leg**: either crypto address or bank + bank_data, depending on what the seller must receive on this lot. **Email body** = seller's text (what they wrote; e.g. bank requisites). Technical data is in the tag; the client shows the body in chat. Buyer uses tag for Core `reservation-by-transaction` with `tx_id`, `crypto`, `address`. |
| `receipt_confirmation` | Buyer sent payment confirmation or the first transaction number. This marks the end of the buyer payment step and starts seller-side verification. |
| `seller_received_funds` | Seller confirmed that the first-leg funds were received. This starts the seller transfer stage. |
| `buyer_destination_details` | Buyer sent destination details for the **second leg** (where the seller must transfer funds to the buyer). Shape mirrors `payment_details` but in the opposite direction: either crypto (`crypto` + `address`) or bank (`bank_data`), depending on what the buyer must receive on this lot. **Email body** = buyer's free-form `message` (shown in chat); technical fields live in the tag. This event must be present in chat so the UI can be restored only from tags. |
| `seller_transfer_tx` | Seller sent the outgoing transfer transaction number/hash for the second leg. Buyer uses this event before the final completion confirmation. |
| `seller_stage_undo` | Seller reverts their **last** seller-owned step. JSON must include `step`: `payment_details` (before buyer first-leg proof), `seller_received_funds` (before `buyer_destination_details`), or `seller_transfer_tx` (before buyer final completion). API may delete pending sale / Core reservation or clear payout tx hash — see `App\Support\SellerStageUndoSupport`. |
| `deal_completed_buyer` | Buyer confirmed final completion of the deal. |
| `buyer_exited`      | Buyer cancelled the deal (e.g. via buyer-cancel-deal). Email is sent **to the seller**. Tag includes `lot_id`. The seller app, when it sees a message with this tag in the deal mailbox, unblocks the "Exit deal" button so the seller can close the deal. |

Other types may be added without changing the format.

---

## Flow matrix by pair

This is the canonical role matrix for the three supported pair types.

### `crypto -> fiat`

Buyer pays crypto first and receives fiat second.

1. Seller sends `payment_details` with a **crypto address** for the first leg.
2. Buyer posts payment proof for the first leg:
   - `buyer_crypto_tx` for manual on-chain reporting; or
   - server-triggered payout flow for AML crypto-first lots.
3. Seller confirms `seller_received_funds`.
4. Buyer sends `buyer_destination_details` with **bank requisites** for the second leg.
5. Seller sends `seller_transfer_tx` as the second-leg proof (bank receipt / receipt links for bank flow).
6. Buyer confirms `deal_completed_buyer`.

### `fiat -> crypto`

Buyer pays fiat first and receives crypto second.

1. Seller sends `payment_details` with **bank requisites** for the first leg.
2. Buyer sends `receipt_confirmation` after bank receipt verification (DKIM / PDF).
3. Seller confirms `seller_received_funds`.
4. Buyer sends `buyer_destination_details` with a **crypto address** for the second leg.
5. Seller sends `seller_transfer_tx` with the outgoing crypto tx id / hash.
6. Buyer confirms `deal_completed_buyer`.

### `crypto -> crypto`

Buyer pays crypto first and receives crypto second.

1. Seller sends `payment_details` with a **crypto address** for the first leg.
2. Buyer posts `buyer_crypto_tx` (or AML server payout equivalent).
3. Seller confirms `seller_received_funds`.
4. Buyer sends `buyer_destination_details` with a **crypto address** for the second leg.
5. Seller sends `seller_transfer_tx` with the outgoing crypto tx id / hash.
6. Buyer confirms `deal_completed_buyer`.

### Important constraints

- `fiat -> fiat` lots are forbidden.
- `payment_details` is always about the **first leg** (what the seller must receive first).
- `buyer_destination_details` is always about the **second leg** (where the seller sends funds to the buyer).
- Buyer-side bank receipt verification belongs only to the stage where the buyer is paying the **first-leg bank transfer**. It must not be re-presented as a later-stage action after buyer payment proof is already submitted.

---

## Seller external mailbox (`seller_notification_email`)

When `start_deal` is sent and `seller_notification_email` differs from the seller’s lot mailbox, the API sends a **second** copy to that address. The body is the same as the buyer’s localized message **plus technical lines** (English) with **open-deal links** so the seller can tap to open the deal on another device or in the browser:

- **Web client (HTTPS):** `{client_web_origin}/open-deal?lot_id=<id>&node_key=<node_domain>`. Pattern: **`https://{domain}`** (production web) and **`https://dev.{domain}`** (dev web) for **any** project domain (`xdexboard.com`, `xrpchange.site`, …). **No** `sandbox.{domain}` web — sandbox API emails use production web. Rules: **`docs/WEB_CLIENT_ORIGINS.md`**.
- The origin is sent as optional JSON **`client_web_origin`** (HTTPS, host only). On **web**, the client uses `window.location.origin`. On **Android**, it derives from API endpoint + optional **`experimentalProjectDomain`** (`PRODUCTION` and `SANDBOX` → production web; `DEV` → `dev.{domain}`).
- If the client omits it, the API falls back: `dev.api.{domain}` → `https://dev.{domain}`; `sandbox.api.{domain}` and `api.{domain}` → `https://{domain}`.
- **App (primary on Android):** `xboard://deal/open?…` on the green button (`DealSellerOpenLinks::appDeepLink`). Do **not** use `intent://` in email — Gmail ignores it; `browser_fallback_url` opens HTTPS inside the mail WebView.
- **App landing (HTTPS):** same **scheme and host as the API request**, path `/open-deal?lot_id=…&node_key=…` — landing redirects to `xboard://` / `intent://` if opened in a browser.
- **App deep link:** `xboard://deal/open?…` (copy fallback), `DealSellerOpenLinks::APP_DEEP_LINK_BASE`.
- The notification is sent as **multipart** (`text/plain` + `text/html`) with separate buttons for web browser and app when links are available.
- `node_key` is the node’s `domain` from the database, lowercased, with a leading `api.` removed (same normalization idea as the client).
- The lot mailbox email and in-app chat copy **do not** include these lines (only the external notification).
- **`send-test-notification-email`** (with `{lot}` or standalone) accepts the same optional **`client_web_origin`** and appends the same link set. Standalone (draft lot before save) also accepts **`open_deal_lot_id`** / **`open_deal_node_key`** from the client (typically `test` + node domain) so the HTML/plain layout matches real notifications.

## External emergency notifications (not the main deal transport)

Deal-tag emails and mailbox chat remain the primary in-system channel. External notification emails are a separate fallback mechanism.

Rules:

- Do not treat external email as the main delivery path for deal messages.
- Do not automatically mirror every deal-tag message to external addresses.
- Automatic external notification is acceptable for a very small number of predefined critical events only.

Current target policy:

- `start_deal`: server may additionally send an external notification to the seller using `exchange_lots.seller_notification_email`.
- Manual emergency escalation from the client chat may trigger an external email when the sender believes the recipient did not notice the in-system IMAP/chat message. Uses **`POST …/{lot}/send-test-notification-email`** (same multipart + links as above).
- Seller-side external escalation should use `seller_notification_email`.
- Buyer-side external escalation is optional and secondary; buyer attention should primarily be handled in the client UI, not by assuming external email is reliable on the first deal.

Important:

- External emergency notification does not replace `X-XBoard-Deal-Tag`.
- Chat restoration and deal stage logic must still rely on the in-system messages/tags.
- If external emergency notifications are sent, they should be considered informational fallback notices, not the system of record for deal state.

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

**Start deal (email to seller):**
```json
{"t":"start_deal","lot_id":42,"selected_amount":100.5,"buyer_locale":"ru"}
```
`selected_amount` and `buyer_locale` are optional.

**Buyer exited (email to seller):**
```json
{"t":"buyer_exited","lot_id":42}
```

**Buyer destination details — crypto (email to seller):**
```json
{"t":"buyer_destination_details","lot_id":42,"tx_id":103,"crypto":"xmr","address":"48edf..."}
```

**Buyer destination details — bank (email to seller):**
```json
{"t":"buyer_destination_details","lot_id":42,"tx_id":104,"bank_data":"Card *1234, Иван Иванов"}
```

**Seller transfer tx number (email to buyer):**
```json
{"t":"seller_transfer_tx","lot_id":42,"tx_id":103,"tx_number":"0xabc123..."}
```

---

## Client behavior

1. **Fetch email** (IMAP) to buyer's deal mailbox.
2. **Parse subject** as JSON. If parsing fails, treat as plain message (no tag).
3. **If `t === "payment_details"`:**
   - Show in chat: for crypto — address (and crypto); for fiat — bank (swift or id) + bank_data; plus any free text from body.
   - `crypto -> crypto` lots are valid, so both `payment_details` and `buyer_destination_details` may contain crypto addresses.
   - `fiat -> fiat` lots are not allowed by product rules and must be rejected at lot create/update validation time.
   - When `tx_id` is present, buyer can call Core open endpoint `GET/POST reservation-by-transaction` with `transaction_id=tx_id`, `crypto_type=crypto`, `wallet_address=address` to get reserved amount.
4. **Tag location:** The tag is sent in the **X-XBoard-Deal-Tag** header so the subject can stay human-readable. Client must read `X-XBoard-Deal-Tag` when displaying deal messages (e.g. from IMAP headers or from the mailbox/chat API that exposes headers). If the header is missing, the message is treated as a plain user message. The email body contains a human-readable summary for the user.

---

## Notes

- **Bank identification:** Use the same as in lot search: prefer `bank_swift`, fallback `bank_id`, so that admins and scripts can identify the bank from the tag.
- **bank_data:** Arbitrary text (card, phone, full requisites); no structure required.
- **Encoding:** JSON in subject must be one line; avoid unescaped newlines or quotes in string values.

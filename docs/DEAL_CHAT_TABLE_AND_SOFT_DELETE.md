# Deal chat table and per-side soft delete (clearing mailbox)

**Status:** Implemented (API + client).  
**Related:** `docs/LOT_MAILBOX_AND_IMAP_CHAT.md`, `docs/DEAL_EMAIL_SUBJECT_TAG.md`.

---

## Purpose

Deal messages between buyer and seller are stored in the API database in the **`deal_chat_messages`** table. The API writes a row whenever it sends a deal-related email (send message to seller/buyer, payment details), and also for chat-only system actors (for example, AI arbiter) that do not send email. The client can read the chat from this table (e.g. on opening the deal screen) and use IMAP as an event source for real-time updates.

When the user **clears the mailbox** (deletes all messages in the deal mailbox via the client), the chat is **not** physically deleted. Records are **marked as cleared per side**: **`deleted_by_seller_at`** and **`deleted_by_buyer_at`**. When the **seller** clears, only `deleted_by_seller_at` is set — messages disappear only for the seller; the buyer still sees them. When the **buyer** clears, only `deleted_by_buyer_at` is set — messages disappear only for the buyer; the seller still sees them. This keeps history for support/audit and allows each side to clear their own view independently.

---

## Table `deal_chat_messages`

| Column            | Type         | Description |
|-------------------|--------------|-------------|
| `id`              | bigint       | Primary key. |
| `exchange_lot_id` | foreign key  | Lot this message belongs to. |
| `sender_side`     | string(20)   | `buyer`, `seller`, `system`, or `arbiter_ai` (chat-only actor). |
| `from_email`      | string(255)  | Sender email (nullable). |
| `to_email`       | string(255)  | Recipient email (nullable). |
| `body`            | text         | Message body. |
| `deal_tag`        | text         | X-XBoard-Deal-Tag header value (JSON), e.g. `start_deal`, `buyer_exited`, `payment_details`. |
| `created_at`      | timestamp    | When the message was created. |
| `updated_at`      | timestamp    | Last update. |
| **`deleted_at`**  | timestamp    | **Nullable.** Set when chat is “cleared”; (Legacy; unused.) Previously used for global soft delete. |

- Index: `(exchange_lot_id, created_at)` for listing messages by lot.
- Model: `App\Models\DealChatMessage` (no `SoftDeletes`; filtering uses the two per-side columns).

---

## When rows are written

The API inserts into `deal_chat_messages` in these cases:

- **sendMessageToSeller** — buyer → seller (e.g. start deal, buyer exited).
- **sendMessageToBuyer** — seller → buyer (e.g. payment details, seller exited).
- **sendPaymentDetails** — seller sends payment instructions to buyer.
- **requestArbiterMessage** — adds AI arbiter chat message without email delivery.

Email-based inserts use the same `body`, `deal_tag`, and sender/recipient as the sent email. Chat-only inserts may keep `from_email` / `to_email` as `null`.

---

## Per-side soft delete (clearing mailbox)

- **Behaviour:** When the user clears the deal mailbox in the client, the client calls **`POST /v1/exchange-lots/{lot}/mark-chat-cleared`**. The API sets **`deleted_by_seller_at`** (if requester is seller) or **`deleted_by_buyer_at`** (if buyer) on all messages for that lot. The other side's view is unchanged.
- **Auth:** Requester must be seller or buyer for this lot (validated via escrow token: `wallet_address` for seller, `token_hash` vs `buyer_escrow_token_hash` for buyer). Returns 403 if not authorized.
- **Response:** `{ "success": true, "message": "Chat marked as cleared.", "count": N }` where `N` is the number of rows updated.
- **Reading chat:** `GET /v1/exchange-lots/{lot}/chat-messages` returns only rows where **`deleted_by_seller_at` IS NULL** (seller) or **`deleted_by_buyer_at` IS NULL** (buyer) (Laravel’s default scope for soft-deleted models). So after “clear mailbox”, the chat list is empty for that lot until new messages are sent.

---

## API endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET    | `/v1/exchange-lots/{lot}/chat-messages` | List deal chat messages for the lot (excluding rows cleared by this side: seller sees where `deleted_by_seller_at` IS NULL, buyer where `deleted_by_buyer_at` IS NULL). Requires escrow token (seller or buyer). |
| POST   | `/v1/exchange-lots/{lot}/arbiter-message` | Add AI arbiter message into chat (chat-only, no email). Optional request field: `message` (context). Requires escrow token (seller or buyer). |
| POST   | `/v1/exchange-lots/{lot}/mark-chat-cleared` | Mark all chat messages as cleared **for this requester only** (set `deleted_by_seller_at` or `deleted_by_buyer_at`). Call when user clears mailbox. Same auth as above. |

Both require the `ValidateEscrowToken` middleware and a valid escrow token for the requesting side (seller or buyer for that lot).

---

## Client behaviour

- **Single source:** The client loads chat only from the API (`GET .../chat-messages`). There is no IMAP fallback; if the API fails, the chat list is empty. The table is the single source of truth.
- When the user taps **“Clear mailbox”** on the deal screen (buyer or seller):
  1. If `apiUrl` is not blank, the client calls **`markChatCleared(apiUrl, nodeDomain, environment, lotId)`** (non-blocking; errors are logged, not shown in UI).
  2. Then it runs **`deleteAllMessagesInMailboxInbox(mb)`** (IMAP delete).
  3. Then it reloads messages (e.g. `loadMessages()`).

So the server marks the chat as cleared **for that side only** when the user clears the mailbox; the client does not delete chat rows on the server, only triggers the mark.

---

## Migrations

- **Create table:** `database/migrations/2026_03_14_000001_create_deal_chat_messages_table.php` — creates the table (including legacy `deleted_at` from `softDeletes()`).
- **Per-side columns:** `database/migrations/2026_03_15_000001_add_deleted_by_seller_buyer_to_deal_chat_messages.php` — adds `deleted_by_seller_at` and `deleted_by_buyer_at`.

Run: `php artisan migrate`.

---

## Pruning old messages (physical delete)

Messages older than **15 days** are permanently deleted to keep the table small. This is done by the scheduled Artisan command **`deal-chat:prune`** (runs daily).

- **Command:** `php artisan deal-chat:prune` — deletes rows where `created_at` is older than 15 days (default).
- **Options:** `--days=15` (default), `--dry-run` (show count only, no delete).
- **Schedule:** Registered in `bootstrap/app.php`; runs daily. Ensure the server runs the Laravel scheduler (e.g. cron: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`).
- **Overview of all pruning tasks:** [SCHEDULED_PRUNING.md](SCHEDULED_PRUNING.md) (includes `transactions:prune`).

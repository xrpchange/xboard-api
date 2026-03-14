# Deal chat table and soft delete (clearing mailbox)

**Status:** Implemented (API + client).  
**Related:** `docs/LOT_MAILBOX_AND_IMAP_CHAT.md`, `docs/DEAL_EMAIL_SUBJECT_TAG.md`.

---

## Purpose

Deal messages between buyer and seller are stored in the API database in the **`deal_chat_messages`** table. The API writes a row whenever it sends a deal-related email (send message to seller/buyer, payment details). The client can read the chat from this table (e.g. on opening the deal screen) and use IMAP as an event source for real-time updates.

When the user **clears the mailbox** (deletes all messages in the deal mailbox via the client), the chat is **not** physically deleted. Records are only **marked as deleted** by setting **`deleted_at`** (soft delete). Deleted = `deleted_at IS NOT NULL`; non-deleted = `deleted_at IS NULL`. This keeps history for support/audit and allows restoring or inspecting cleared chats if needed.

---

## Table `deal_chat_messages`

| Column            | Type         | Description |
|-------------------|--------------|-------------|
| `id`              | bigint       | Primary key. |
| `exchange_lot_id` | foreign key  | Lot this message belongs to. |
| `sender_side`     | string(20)   | `buyer`, `seller`, or `system`. |
| `from_email`      | string(255)  | Sender email (nullable). |
| `to_email`       | string(255)  | Recipient email (nullable). |
| `body`            | text         | Message body. |
| `deal_tag`        | text         | X-XBoard-Deal-Tag header value (JSON), e.g. `start_deal`, `buyer_exited`, `payment_details`. |
| `created_at`      | timestamp    | When the message was created. |
| `updated_at`      | timestamp    | Last update. |
| **`deleted_at`**  | timestamp    | **Nullable.** Set when chat is “cleared”; record is then excluded from normal queries (soft delete). Deleted = `deleted_at IS NOT NULL`; non-deleted = `deleted_at IS NULL`. |

- Index: `(exchange_lot_id, created_at)` for listing messages by lot.
- Model: `App\Models\DealChatMessage` with `SoftDeletes` trait.

---

## When rows are written

The API inserts into `deal_chat_messages` after successfully sending deal email in:

- **sendMessageToSeller** — buyer → seller (e.g. start deal, buyer exited).
- **sendMessageToBuyer** — seller → buyer (e.g. payment details, seller exited).
- **sendPaymentDetails** — seller sends payment instructions to buyer.

Each insert uses the same `body`, `deal_tag`, and sender/recipient as the sent email.

---

## Soft delete (clearing mailbox)

- **Behaviour:** When the user clears the deal mailbox in the client, the client calls **`POST /v1/exchange-lots/{lot}/mark-chat-cleared`**. The API does **not** delete rows from `deal_chat_messages`. It only sets **`deleted_at`** on all messages for that lot (Laravel soft delete: `DealChatMessage::where('exchange_lot_id', $lot->id)->delete()`).
- **Auth:** Requester must be seller or buyer for this lot (validated via escrow token: `wallet_address` for seller, `token_hash` vs `buyer_escrow_token_hash` for buyer). Returns 403 if not authorized.
- **Response:** `{ "success": true, "message": "Chat marked as cleared.", "count": N }` where `N` is the number of rows soft-deleted.
- **Reading chat:** `GET /v1/exchange-lots/{lot}/chat-messages` returns only rows where `deleted_at` is null (Laravel’s default scope for soft-deleted models). So after “clear mailbox”, the chat list is empty for that lot until new messages are sent.

---

## API endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET    | `/v1/exchange-lots/{lot}/chat-messages` | List deal chat messages for the lot (excluding soft-deleted). Requires escrow token (seller or buyer). |
| POST   | `/v1/exchange-lots/{lot}/mark-chat-cleared` | Mark all chat messages for the lot as cleared (set `deleted_at`). Call when user clears mailbox. Same auth as above. |

Both require the `ValidateEscrowToken` middleware and a valid escrow token for the requesting side (seller or buyer for that lot).

---

## Client behaviour

- **Single source:** The client loads chat only from the API (`GET .../chat-messages`). There is no IMAP fallback; if the API fails, the chat list is empty. The table is the single source of truth.
- When the user taps **“Clear mailbox”** on the deal screen (buyer or seller):
  1. If `apiUrl` is not blank, the client calls **`markChatCleared(apiUrl, nodeDomain, environment, lotId)`** (non-blocking; errors are logged, not shown in UI).
  2. Then it runs **`deleteAllMessagesInMailboxInbox(mb)`** (IMAP delete).
  3. Then it reloads messages (e.g. `loadMessages()`).

So the server marks the chat as cleared (soft delete) when the user clears the mailbox; the client does not delete chat rows on the server, only triggers the mark.

---

## Migration

Migration: `database/migrations/2026_03_14_000001_create_deal_chat_messages_table.php`.  
Creates the table including `$table->softDeletes()` for `deleted_at`.

Run: `php artisan migrate` (or migrate that file specifically).

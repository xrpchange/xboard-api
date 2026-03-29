# Deal dispute state (deal_disputes)

**Status:** Implemented (API DB layer + auto-sync from existing deal tags).  
**Related:** `docs/DEAL_CHAT_TABLE_AND_SOFT_DELETE.md`.

---

## Purpose

`deal_chat_messages` stores the conversation history (buyer/seller/arbiter).  
`deal_disputes` stores the **dispute lifecycle state** for a lot (opened, in review, closed).

This separation avoids parsing chat history to understand dispute status.

---

## Table `deal_disputes`

| Column | Type | Description |
|---|---|---|
| `id` | bigint | Primary key. |
| `exchange_lot_id` | foreign key | Lot this dispute belongs to. |
| `opened_by` | string(20) | `buyer`, `seller`, `system`. |
| `status` | string(20) | `opened`, `in_review`, `resolved`, `closed`. |
| `opened_message_id` | foreign key nullable | Optional link to first chat message that opened dispute. |
| `reason_code` | string(100) nullable | Optional reason code. |
| `resolved_by` | string(20) nullable | `buyer`, `seller`, `arbiter_ai`, `human_admin`, `system`. |
| `resolution` | text nullable | Optional resolution details. |
| `arbiter_id` | string(64) nullable | Arbiter identity (e.g. `arbiter_ai_v1`). |
| `arbiter_model_version` | string(64) nullable | Optional model version marker. |
| `opened_at` | timestamp nullable | When dispute was opened. |
| `resolved_at` | timestamp nullable | When resolved. |
| `closed_at` | timestamp nullable | When closed. |
| `last_activity_at` | timestamp nullable | Latest dispute-related activity. |
| `created_at`, `updated_at` | timestamps | Default Laravel timestamps. |

Indexes:

- `(exchange_lot_id, status)`
- `(status, created_at)`

---

## Auto-sync rules

Dispute state is synchronized from existing deal actions:

- `deal_tag.t = dispute_requested_by_buyer` or `dispute_requested_by_seller` (and legacy `dispute_requested`) -> create/open dispute in `deal_disputes` with `status = opened`.
- `POST /v1/exchange-lots/{lot}/arbiter-message` -> create/open dispute if needed and switch to `status = in_review`.
- Deal exit actions (`buyer_exited`, `seller_exited`, buyer cancel flow) -> close active disputes with system resolution text.
- Deal completion tags (`deal_completed_buyer`, `deal_completed_seller`) -> close active disputes.

---

## API endpoints

| Method | Endpoint | Description |
|---|---|---|
| GET | `/v1/exchange-lots/{lot}/dispute-status` | Returns latest active dispute (`opened`/`in_review`) for the lot or `null`. |
| POST | `/v1/exchange-lots/{lot}/arbiter-message` | Adds arbiter message to chat and updates dispute state to `in_review`. |

Auth:

- Same as chat endpoints (`ValidateEscrowToken`).
- Requester must be current seller or buyer for the lot.

### AI arbiter (server-side)

Full setup, env vars, failover, security, and curl checklist: **[docs/AI_ARBITER_SERVER.md](AI_ARBITER_SERVER.md)** (includes **GigaChat** + DeepSeek and other providers).

- **Dispute row** `deal_disputes.status` remains `in_review` after an arbiter message (so `GET .../dispute-status` keeps returning the active row).
- **Semantic outcome** is returned as `arbiter_status` (`in_review` | `manual_review`) in the POST JSON and in `deal_chat_messages.deal_tag` for `t=arbiter_message`.
- Optional body fields: `message`, `provider` / `provider_hint` (hint only; **not** used to choose the LLM provider), `bank_timezone`, `payment_time_raw`, `pdf_sha256`, `email_message_sha256`, `dkim_signature_sha256`.

---

## Migration

- `database/migrations/2026_03_18_000001_create_deal_disputes_table.php`

Run:

```bash
php artisan migrate
```

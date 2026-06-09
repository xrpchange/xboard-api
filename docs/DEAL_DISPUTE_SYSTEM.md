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
| GET | `/v1/exchange-lots/{lot}/dispute-status` | Returns latest active dispute (`opened`/`in_review`) for the lot or `null`, plus **`arbiter_ai`** usage for UI (invocation cap). |
| POST | `/v1/exchange-lots/{lot}/arbiter-message` | Reserves one **AI invocation slot** on the lot (when cap &gt; 0), then adds arbiter message to chat and updates dispute state to `in_review`. Returns **422** + `error_code: ai_arbiter_iteration_limit_reached` when the cap is exhausted. |

Auth:

- Same as chat endpoints (`ValidateEscrowToken`).
- Requester must be current seller or buyer for the lot.

### AI arbiter (server-side)

Full setup, env vars, single-provider policy (no vendor failover), security, and curl checklist: **[docs/AI_ARBITER_SERVER.md](AI_ARBITER_SERVER.md)**.

- **Dispute row** `deal_disputes.status` remains `in_review` after an arbiter message (so `GET .../dispute-status` keeps returning the active row).
- **Semantic outcome** is returned as `arbiter_status` (`in_review` | `manual_review`) in the POST JSON and in `deal_chat_messages.deal_tag` for `t=arbiter_message`.
- Optional body fields: `message`, `provider` / `provider_hint` (hint only; **not** used to choose the LLM provider), `bank_timezone`, `payment_time_raw`, `pdf_sha256`, `email_message_sha256`, `dkim_signature_sha256`.
- After an operator reply in dispute chat, the next review is treated as a fresh pass: payment/bank/crypto checks are re-run by the normal rules-first logic (no shortcut mode).
- While dispute is active (`opened` / `in_review`), `buyerCancelDeal` and `seller_exited` are locked for `DEAL_ACTIVE_DISPUTE_EXIT_LOCK_HOURS` (default 24h). After lock expiry, legacy timeout/side exit rules apply again even if dispute is still open.

### Anti-abuse guards for dispute opening

- Dispute tag (`dispute_requested*`) is rejected before first-leg payment proof stage. Buyer/seller can open dispute only once flow reached post-payment states.
- Opening dispute requires payment proof basis:
  - bank: receipt links persisted in `transactions.receipt_links_json`, or
  - crypto: verified `core_tx_check.verified=true` in chat history.
- Receipt anti-duplicate: server computes/stores `sha256` for receipt files in `receipt_file_checksums` and rejects re-sending the same receipt checksum in deal chat (`duplicate_receipt_checksum`).
- `receipt_file_checksums` is pruned by `receipt-checksums:prune` with the same TTL window as `deal_chat_messages`.
- Receipt time sanity for dispute open: if receipt payment time is provided and is too far before deal start, server rejects dispute (`bank_receipt_time_too_old_for_deal`), with UTC grace window `DEAL_BANK_RECEIPT_DISPUTE_TIME_GRACE_HOURS` (default 12).

### Crypto tx id verification via Core

When participants send crypto proof tags in deal chat (`deal_tag.t = buyer_crypto_tx` or `seller_transfer_tx` with `tx_ref`), Laravel now calls Core endpoint:

- `POST /api/escrow-tokens/verify-external-payment`

The tx id must be verifiable on-chain by Core (`data.verified=true`), otherwise message sending is rejected with **422**:

- `error_code = crypto_tx_not_verified` (tx not confirmed / mismatched / not found), or
- `error_code = crypto_tx_amount_too_low` (on-chain amount is lower than required deal amount), or
- `error_code = core_crypto_tx_verify_*` for Core-side transport/HTTP failures.

Successful checks are written into `deal_tag.core_tx_check` (source, UTC check time, status, confirmations, checks) for auditability in chat history.

### `GET …/dispute-status` response extras

The JSON always includes **`dispute`**: an object for the latest active row (`opened` / `in_review`), or **`null`** if none. It always includes **`arbiter_ai`** (usage counter for the lot — independent of whether a dispute row exists):

| JSON path | Type | Description |
|-----------|------|-------------|
| `arbiter_ai.invocations` | int | Value of `exchange_lots.arbiter_ai_invocations` after refresh. |
| `arbiter_ai.max_invocations` | int \| null | Cap from `config('deal.max_ai_arbiter_invocations_per_lot')`, or `null` if cap disabled (`0`). |
| `arbiter_ai.limit_reached` | bool | `true` when cap is enabled and `invocations >= max_invocations`. |
| `arbiter_participant.action` | string | Per-side arbiter button: `comment_required` \| `ai` \| `operator_escalation` \| `wait_for_operator`. |
| `arbiter_participant.ai_used_in_cycle` | bool | This side already received an `arbiter_message` with matching `source_side` since the last `operator_mail_reply` (or since dispute open). |
| `arbiter_participant.operator_escalation_sent_in_cycle` | bool | This side already has `operator_escalation_requested` with matching `requested_by` in the current cycle. |
| `arbiter_participant.has_participant_comment` | bool | At least one chat row from this side exists since cycle start. |

Full rationale for the counter and the **422** contract: **`docs/AI_ARBITER_SERVER.md`** (*Per-lot AI invocation budget*). Participant button policy (one AI per cycle, then operator mail tag `operator_escalation_requested`): same doc, *Participant arbiter button policy*.

### Exchange lot column (not in `deal_disputes`)

| Column | Table | Role |
|--------|--------|------|
| `arbiter_ai_invocations` | `exchange_lots` | Incremented atomically at the start of each `POST …/arbiter-message` when the cap is enabled. |

---

## Migration

- `database/migrations/2026_03_18_000001_create_deal_disputes_table.php`
- `database/migrations/2026_04_19_000001_add_arbiter_ai_invocations_to_exchange_lots.php` — AI arbiter **invocation** budget per lot (see `docs/AI_ARBITER_SERVER.md`).

Run:

```bash
php artisan migrate
```

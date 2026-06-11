# Buyer external email reputation

Persistent statistics keyed by the buyer's **external notification email** (`buyer_notification_email` on `exchange_lots`, set at `start_deal`).

Unlike lot counters (`completed_deals_count` / `bad_deals_count` on `exchange_lots`), buyer reputation follows the person across lots and nodes when they reuse the same external mailbox.

## Storage

Table `buyer_external_email_reputations`:

| Column | When incremented |
|--------|------------------|
| `completed_deals_count` | Each completed `sale` transaction for a deal with that external email |
| `bad_deals_count` | A dispute on that deal was **closed with the buyer's claim rejected** (operator or AI verdict `favor_seller`) |

Email is normalized: `trim` + `lowercase`; invalid addresses are ignored.

`sale` rows also store `transactions.buyer_notification_email` as a snapshot at `sendPaymentDetails` (same moment as payment-details transaction creation).

## `completed_deals_count`

- Incremented in `Transaction` model when a `sale` row becomes `status = completed` (same hook as lot `completed_deals_count`).
- Uses `transactions.buyer_notification_email` when set; otherwise falls back to `exchange_lots.buyer_notification_email` for the lot.

## `bad_deals_count` (disputed deals for this buyer)

**Semantics:** how many times this external email was involved in a dispute that ended with the **buyer's claim rejected** — no funds moved from seller escrow to the buyer by the operator on that resolution.

Increment paths (+1 per lot, once per closed dispute batch):

- Operator signed link **Deny buyer** (`deny_buyer` → `favor_seller`) — `ArbiterOperatorSignedActionService::applyDisputeVerdict`.
- AI arbiter `favor_seller` when active disputes are closed (`ExchangeLotController::closeOpenDisputesForLot` with `arbiter_verdict = favor_seller`).

Not incremented when:

- Operator **compensates buyer** from seller escrow (`compensate_buyer` / `favor_buyer` payout) — that increments **lot** `bad_deals_count`, not buyer reputation.
- Dispute is closed without `favor_seller` (buyer/seller exit, deal completed by participants, `favor_buyer` without operator deny, etc.).
- Operator retries when disputes were already closed (`already_closed`).

## Contrast with lot `bad_deals_count`

Both counters use the column name `bad_deals_count` and are shown in the client as neutral “disputed deals” counts, but **the trigger events differ**:

| Scope | `bad_deals_count` means |
|-------|-------------------------|
| **Lot** (`exchange_lots`) | Operator paid the buyer **from seller escrow reservation** after a dispute (`compensate_buyer` or AI `favor_buyer` + successful auto payout). See **`docs/EXCHANGE_LOT_DEAL_COUNTERS.md`**. |
| **Buyer email** (`buyer_external_email_reputations`) | Operator or AI **rejected the buyer's dispute claim** (`deny_buyer` / closed `favor_seller`). No operator payout from reservation on that resolution. |

A single dispute normally increments **one** of these counters, not both: compensation → lot; rejection of buyer claim → buyer email.

## API JSON

`ExchangeLotResource` exposes `buyer_completed_deals_count` and `buyer_bad_deals_count` only for the **seller** (same gate as `buyer_last_seen_ip`: seller escrow token or seller placed-lots query). Omitted when the lot has no valid `buyer_notification_email`.

## Migration backfill

`2026_06_11_000001_create_buyer_external_email_reputations_table` rebuilds counters from completed sales and closed `favor_seller` disputes, and copies `buyer_notification_email` onto historical `sale` rows from the lot.

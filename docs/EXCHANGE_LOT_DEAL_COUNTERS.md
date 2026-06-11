# Exchange lot deal counters

Persistent statistics on `exchange_lots` (survive lot edit; unlike `sold_crypto_amount` they are **not** reset when the seller saves the lot).

## Columns

| Column | Type | When incremented | Reset on lot edit? |
|--------|------|------------------|-------------------|
| `sold_crypto_amount` | decimal | Each completed `sale` transaction (seller-leg crypto volume) | **Yes** → `0` on `PUT/PATCH` lot |
| `completed_deals_count` | unsigned int | Same event as `sold_crypto_amount` (+1 per completed sale) | **No** |
| `bad_deals_count` | unsigned int | Buyer compensated from **seller escrow reservation** after a dispute | **No** |

## `completed_deals_count`

- Incremented in `Transaction` model when a `sale` row becomes `status = completed` (same hook as `sold_crypto_amount`).
- Migration backfills from `COUNT(*)` of completed `sale` transactions per lot.

## `bad_deals_count` (disputed deals on this lot)

**Semantics:** how many times on this lot a dispute ended with the **operator paying the buyer from the seller's escrow reservation** (Core reservation payout).

Counts deals where:

1. A dispute was open on the lot, and
2. The buyer was paid from seller escrow (Core reservation payout).

Increment paths (+1 per lot per successful payout):

- Operator signed link **Compensate buyer** with a **new** successful Core payout (`ArbiterOperatorSignedActionService::compensateBuyer`).
- AI arbiter `favor_buyer` with `ARBITER_AUTO_CORE_PAYOUT_ON_FAVOR_BUYER=true` and successful auto payout (`ExchangeLotController::requestArbiterMessage`).

Not incremented when:

- Dispute is closed favor buyer **without** seller-escrow payout (manual seller payout only; operator did not move reservation funds).
- Operator **Deny buyer** (`favor_seller`, no payout) — that increments **buyer email** `bad_deals_count`, not the lot. See **`docs/BUYER_EXTERNAL_EMAIL_REPUTATION.md`**.
- Operator retries `compensate_buyer` when payout already exists (`already_compensated`).

## Contrast with buyer email `bad_deals_count`

Both counters use the name `bad_deals_count` and are shown in the client as neutral “disputed deals” counts, but **the trigger events differ**:

| Scope | `bad_deals_count` means |
|-------|-------------------------|
| **Lot** (this document) | Operator paid buyer **from seller escrow reservation** after a dispute. |
| **Buyer email** (`buyer_external_email_reputations`) | Operator or AI **rejected the buyer's dispute claim** (`deny_buyer` / closed `favor_seller`). |

A single dispute normally increments **one** of these counters, not both.

## API JSON

`ExchangeLotResource` exposes `completed_deals_count` and `bad_deals_count` alongside `sold_crypto_amount`.

## Related: buyer reputation

Buyer-side counters keyed by external notification email: **`docs/BUYER_EXTERNAL_EMAIL_REPUTATION.md`** (`buyer_completed_deals_count` / `buyer_bad_deals_count`, seller-only in API).

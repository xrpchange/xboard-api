# Exchange lot deal counters

Persistent statistics on `exchange_lots` (survive lot edit; unlike `sold_crypto_amount` they are **not** reset when the seller saves the lot).

## Columns

| Column | Type | When incremented | Reset on lot edit? |
|--------|------|------------------|-------------------|
| `sold_crypto_amount` | decimal | Each completed `sale` transaction (seller-leg crypto volume) | **Yes** → `0` on `PUT/PATCH` lot |
| `completed_deals_count` | unsigned int | Same event as `sold_crypto_amount` (+1 per completed sale) | **No** |
| `bad_deals_count` | unsigned int | Buyer compensated from **seller escrow** after a dispute | **No** |

## `completed_deals_count`

- Incremented in `Transaction` model when a `sale` row becomes `status = completed` (same hook as `sold_crypto_amount`).
- Migration backfills from `COUNT(*)` of completed `sale` transactions per lot.

## `bad_deals_count`

Counts deals where:

1. A dispute was open on the lot, and
2. The buyer was paid from seller escrow (Core reservation payout).

Increment paths:

- Operator signed link `compensate_buyer` with a **new** successful Core payout (`ArbiterOperatorSignedActionService::compensateBuyer`).
- AI arbiter `favor_buyer` with `ARBITER_AUTO_CORE_PAYOUT_ON_FAVOR_BUYER=true` and successful auto payout (`ExchangeLotController::requestArbiterMessage`).

Not incremented when:

- Dispute is closed favor buyer **without** seller-escrow payout (manual seller payout only).
- Operator retries `compensate_buyer` when payout already exists (`already_compensated`).

## API JSON

`ExchangeLotResource` exposes `completed_deals_count` and `bad_deals_count` alongside `sold_crypto_amount`.

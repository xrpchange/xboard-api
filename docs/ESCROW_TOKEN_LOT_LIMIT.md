# Escrow token lot limit

**Purpose:** Cap how many `exchange_lots` rows one seller escrow token may own in the API database. Protects against unbounded lot sprawl per token.

## Config

| Key | Env | Default | Meaning |
|-----|-----|---------|---------|
| `lot.max_lots_per_escrow_token` | `LOT_MAX_LOTS_PER_ESCROW_TOKEN` | **10** | Max rows with the same `wallet_hash`. **`0`** = unlimited (cap disabled). |

File: `config/lot.php`.

## Enforcement

- **When:** `POST /api/v1/exchange-lots` (`ExchangeLotController::store`), after `wallet_hash` is parsed from the escrow token public part and before `ExchangeLot::create`.
- **Key:** `exchange_lots.wallet_hash` (lowercase `token_hash` from the token JSON).
- **Scope:** All lots for that hash (active and inactive). Lot **update** does not create a new row and is not limited.
- **Response:** HTTP **422**, `error_code`: `escrow_token_lot_limit_reached`, fields `max_lots_per_escrow_token`, `current_lot_count`.

## Related limits (not the same)

| Feature | Doc |
|---------|-----|
| Token **creation** rate (email / IP) | `docs/TOKEN_CREATION_RATE_LIMIT.md` |
| Deal chat / arbiter caps per lot | `config/deal.php`, `docs/DEAL_CHAT_TABLE_AND_SOFT_DELETE.md` |

## Deal counters on the lot row

See `docs/EXCHANGE_LOT_DEAL_COUNTERS.md` for `completed_deals_count` and `bad_deals_count` on `exchange_lots`.

# Core API key and API-only endpoints

## Overview

The API (xboard-api) calls Core (xboard-core) for token creation, reservations, and deal exit/cancel. Several Core endpoints **must be called only from the API**: they require the **X-Core-Key** header. Clients never call Core directly for these; they call the API, and the API calls Core with the shared secret.

## Required configuration

### API (.env)

Set the same secret in the API as in Core:

- **CORE_KEY** — used when calling **sandbox** Core (e.g. `CORE_URL` / sandbox.api.core.*). Must match Core `[api] CORE_API_KEY` in sandbox config.
- **CORE_PRODUCTION_KEY** — used when calling **production** Core (e.g. `CORE_PRODUCTION_URL`). Must match Core `[api] CORE_API_KEY` in production config.
- **CORE_CREATE_TIMEOUT** (default **120** seconds) — HTTP timeout for `POST /api/escrow-tokens/create`. The default **CORE_TIMEOUT** (10s) is too short when Core pre-funds Stellar testnet wallets (friendbot + trustline). Use **CORE_PRODUCTION_CREATE_TIMEOUT** for production if needed.

The same key value can be used for both sandbox and production if both Core configs use the same `CORE_API_KEY`. Generate a secure value, for example:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

### Core (config.*.ini)

In each Core config (e.g. `config.sandbox.ini`, `config.prod.ini`) add:

```ini
[api]
CORE_API_KEY = <same value as API CORE_KEY / CORE_PRODUCTION_KEY>
```

If `CORE_API_KEY` is missing or empty, API-only endpoints return **500** with a message asking to set it. If the request is sent without **X-Core-Key** or with a wrong value, Core returns **403 Forbidden**.

## API-only endpoints in Core

| Core endpoint | When the API calls it |
|---------------|------------------------|
| **POST /api/escrow-tokens/create** | Token creation. Client calls API `POST /api/v1/escrow-tokens/create`; API calls Core with X-Core-Key. No direct client→Core token creation. |
| **POST /api/delete-reservation** | Seller exits deal. API sends `seller_exited` message and calls Core to release the reservation. |
| **POST /api/delete-reservation-by-buyer** | Buyer cancels/exits deal. API calls Core to delete **all** reservation rows for `exchange_lot_id` on that Core env (seller payment-details hold + buyer rows). API also calls **delete-reservation** with the seller token when available. |

Business rules (who may create tokens, who may exit or cancel) are enforced in the **API**. Core only checks the key and executes the operation.

## References

- Core: `docs/LOT_RESERVATIONS_AND_EXCHANGE_LOTS.md` (section “API-only endpoints”).
- Core config: `[api] CORE_API_KEY` in `config.sandbox.ini` / `config.prod.ini`.
- API config: `config/services.php` → `services.core.key`, `services.core_production.key` (from `CORE_KEY`, `CORE_PRODUCTION_KEY`).

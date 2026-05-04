# Administrator mode — security model (API + Core)

This document fixes how **client “Administrator mode”** relates to **server-side changes**. It complements `config/rate_limit.php` (`admin_mode_enable`) and the `EscrowTokenController` methods below.

## Two different secrets

| Secret | Typical config (Core) | Role |
|--------|----------------------|------|
| **`admin_mode_password`** | `admin.admin_mode_password` | Unlocks Administrator mode in the **client** (Developer settings), authorizes **read-oriented** API/Core checks that use this password, and gates disclosure of **`admin_email`**. |
| **`admin_password`** (Core) | Used by Core admin endpoints that **change** data | Required for **mutations** (e.g. updating Core fee scalars, fee wallet fields) **together with a one-time code sent to the admin mailbox**. |

The client stores `admin_mode_password` locally after a successful authorize call (platform secure storage). That does **not** replace the **email + code + `admin_password`** requirement for writes.

## What actually changes parameters on the server

Any flow that **persists** Core configuration from the Laravel API is designed to require **both**:

1. **Email proof** — one-time **verification code** delivered to the configured address (same pattern as fee-wallet verification).
2. **`admin_password`** — the Core admin credential checked by the relevant Core endpoint.

Examples (non-exhaustive):

- `POST /api/v1/escrow-tokens/set-crypto-fee-params` — body includes `email`, `verification_code`, `admin_password`, … (see README *Fee Wallet Admin Flow* and controller validation).
- `POST /api/v1/escrow-tokens/set-fee-wallet-address` — `verification_code` + `admin_password`, …

So **`admin_mode_password` alone does not grant the ability to rewrite Core INI** through these paths. Brute-forcing or leaking it primarily affects **confidentiality** of things gated only by that secret (e.g. **`admin_email`** exposure, client UI unlock, read APIs that use `admin_mode_password`), not **integrity** of fee/config on the server when the second factor is enforced.

## Public vs token-protected endpoints

- **`POST /api/v1/escrow-tokens/authorize-admin-mode`** — public; checks `admin_mode_password` against Core; **daily attempt limit** per `device_id` or client IP (`rate_limit.admin_mode_enable`, env `RATE_LIMIT_ADMIN_MODE_ATTEMPTS_PER_DAY`). See `EscrowTokenController::authorizeAdminMode`.
- **`POST /api/v1/escrow-tokens/admin-email`** — public; returns `admin_email` after Core validates `admin_mode_password`.
- **`POST /api/v1/escrow-tokens/get-crypto-fee-params`** — requires **`X-Escrow-Token`** plus `admin_mode_password`; read-only from Core.

Mutating routes live under the **escrow-token–protected** group and use the **email + code + `admin_password`** pattern as above.

## Operational note

Keeping **`admin_mode_password`** strong is still good practice (defense in depth, limiting who can learn `admin_email` or use read-only admin APIs). The **main guarantee** for not allowing unchecked server edits is the **mailbox verification + `admin_password`** layer on each write.

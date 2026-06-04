# Deal AML state (`transactions` only)

## Principle

This is an exchange, not a long-term compliance archive. **All deal AML data for an active sale lives on the pending `transactions` row** in `aml_check_json`. Rows are purged with normal transaction cleanup (user action or scheduled job).

**No** separate AML log tables (`deal_aml_checks`, `deal_aml_charges`, etc.). The only schema change for deal AML is column **`transactions.aml_check_json`** (migration `2026_04_30_000003_add_aml_check_json_to_transactions`).

## Core vs API

| Layer | Role |
|-------|------|
| **Core** | Runtime screening only (`performDealAmlScreeningOrSkip`). No AML persistence in Core DB. |
| **API** | Merges screening snapshots + reserve debits into `transactions.aml_check_json`; operator alert on provider failures. |
| **Client** | Server payout actions; localized errors for two failure classes (see below). |
| **API `start_deal`** | When the buyer’s first leg is on-chain crypto and `exchange_lots.aml` is true: reject if `X-Escrow-Token` / `escrow_token` Core type ≠ `DealEscrowCryptoType::buyerCoreType`, or Core `reservation-requirements` (`reservation_side=buyer`) shows insufficient `available` / gas. Spec **LOT-016** — `App\Support\StartDealBuyerEscrowValidation`. Mirrors client `LotDetailScreen` + `ExchangeLot.buyerFirstLegPaysCryptoOrNull()`. |

Cross-repo flow: **`xboard-core/docs/AML_DEAL_FLOW.md`**.

## Two failure classes (only these)

| Class | Meaning | Core HTTP | Core `error_code` | `token_charges` | Operator |
|-------|---------|-----------|-------------------|-----------------|----------|
| **User fault** | Wallet did not pass clean-wallet / risk rules | `422` | `AML_CHECK_FAILED` | **Yes** — idempotent debit on pending sale tx | — |
| **Provider / server fault** | AMLBot down, unpaid account, timeout, misconfiguration, etc. | `503` | `AML_PROVIDER_UNAVAILABLE` | **No** | Plain-text email with full Core JSON payload |

### User-fault `reason_code` values (Core)

- `aml_blacklist_flag`
- `aml_risk_threshold_exceeded`
- `aml_stub_fail` (sandbox stub)

### Provider-fault `reason_code` values (Core)

- `aml_provider_failed`
- `aml_provider_error`
- `aml_provider_unknown_status`
- Screening exceptions (HTTP/timeout from AMLBot) are treated as provider fault.

API applies charges only when `DealAmlPersistenceService::isUserFaultAmlFailure()` is true (`AML_CHECK_FAILED` or user-fault `reason_code` in the `aml` block). **`AML_PROVIDER_UNAVAILABLE` never creates `token_charges`.**

## Operator alert email (`AML_ADMIN_ALERT_EMAIL`)

When Core returns **`AML_PROVIDER_UNAVAILABLE`**, `ExchangeLotController` calls `DealAmlAdminAlertService::notifyProviderFailure()` after persisting the AML snapshot on the sale transaction.

**Recipient resolution** (first non-empty wins):

1. **`AML_ADMIN_ALERT_EMAIL`** in Laravel `.env` → `config('services.aml.admin_alert_email')` — optional override for ops/testing.
2. **Core `[admin] admin_email`** — API `POST {CORE_URL}/api/internal/notification-admin-email` with `X-Core-Key` (same inbox as **admin verification codes** for fee-wallet / Core settings; see `docs/ADMIN_MODE_SECURITY.md`).
3. **`ARBITER_OPERATOR_ESCALATION_EMAIL`** — `config('arbiter_operator_mail.escalation_email')` fallback if Core inbox is unreachable or empty.

If no address is resolved, API logs a warning and skips send (deal still fails with `503` to the client).

**Mail:** `App\Mail\DealAmlProviderFailureMail` — English, plain text. Subject: `XBoard critical: AML screening provider failure (lot {id})`. Body includes lot id, transaction id, payout context, screening side, Core HTTP status, `error_code`, note that **no participant charge was applied**, and **pretty-printed full JSON** of the Core response body.

**Requirements:** Laravel mail must be configured (`MAIL_*` in `.env`; see `docs/NOREPLY_MAILBOX_SETUP.md`). Core must expose `admin_email` in `config/config.*.ini` for the default path.

### Example `.env`

```env
# Optional. If empty, API uses Core [admin] admin_email (verification inbox), then ARBITER_OPERATOR_ESCALATION_EMAIL.
AML_ADMIN_ALERT_EMAIL=
```

## `aml_check_json` shape (sale transaction)

```json
{
  "last_context": "buyer_crypto_payout_to_seller",
  "last_http_status": 422,
  "last_aml": { "provider": "stub", "status": "failed", "cost_amount": 0.4, "reason_code": "aml_risk_threshold_exceeded", ... },
  "last_error_code": "AML_CHECK_FAILED",
  "last_core_at": "2026-05-22T12:00:00+00:00",
  "buyer_screening": {
    "side": "buyer",
    "screened_token_hash": "...",
    "passed": true,
    "cost_amount": 0.25,
    ...
  },
  "seller_screening": { ... },
  "token_charges": [
    {
      "charge_key": "seller-fail-own:42:101:...",
      "charged_token_hash": "...",
      "charged_side": "seller",
      "amount": 0.4,
      "currency_code": "usdt_tron",
      "charge_reason": "seller_failed_own_check"
    }
  ]
}
```

- **`buyer_screening` / `seller_screening`** — last result per side on **this** transaction (not overwritten by the other side).
- **`token_charges`** — AML reserve debits for **user-fault** failed checks only (idempotent `charge_key`). Used to increase `aml_fee` while the deal is open.

## Write path

`DealAmlPersistenceService::handlePayoutCoreResponse()` after Core deal-payout (buyer / seller / arbiter paths in `ExchangeLotController`).

Provider failure: same service merges snapshot; controller calls `persistPayoutAmlAndAlertOperatorIfNeeded()` → admin mail when `error_code === AML_PROVIDER_UNAVAILABLE`.

## Outstanding reserve (Core → API)

`GET /api/v1/internal/aml/outstanding-charges?token_hash=...&is_test=0|1`

Sums `token_charges[].amount` where `charged_token_hash` matches, across **`transactions` with `tx_type=sale`, `status=pending`** only (active deals).

Auth: `X-Core-Key` (middleware `core.key`). Controller: `DealAmlInternalController`.

Core config: `[api] LARAVEL_API_BASE_URL`, `laravel_api_timeout_seconds` — see `xboard-core/docs/AML_DEAL_FLOW.md`.

## Charge rules (user fault only)

- Buyer AML fail → charge buyer token (screening `cost_amount`).
- Seller AML fail → charge seller token + optional prior buyer screening `cost_amount` from `buyer_screening` on the same transaction when buyer had already passed.

## Client behavior

- `AML_CHECK_FAILED` → `DealAmlCheckFailedException` — localized message may include risk score from `aml` payload.
- `AML_PROVIDER_UNAVAILABLE` → `DealAmlProviderUnavailableException` — keys `deal.amlProviderUnavailable` (EN/RU): explicitly states **no charge** on the token.

Parsing: `RemoteDataSourceImpl.failureFromCryptoPayoutResponse()`; UI helper `DealAmlPayoutErrors.kt`.

## Related

- `docs/DEAL_STATE_AND_EXIT_RULES.md` — payout routes and stage preservation
- `xboard-core/docs/AML_DEAL_FLOW.md` — Core screening and HTTP codes
- `xboard-client/docs/DEAL_IN_PROGRESS_SCREENS.md` — AML crypto-first / crypto-second UI
- `docs/SPEC_REGISTRY.md` — `AML-001`
- `config/services.php` — `services.aml.admin_alert_email`

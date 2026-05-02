# Sandbox virtual receipt bank

Synthetic bank entry for **sandbox / dev API hosts** (`sandbox.*`, `dev.*`), or hostnames you list in **`SANDBOX_VIRTUAL_BANK_EXTRA_PREPEND_HOSTS`**. It is **not** stored in the `banks` table.

## Why the virtual bank is missing from `/banks`

Check Laravel log line **`RefData: GET /banks sandbox_virtual_bank`**:

| `outcome` | Meaning |
|-----------|---------|
| `skipped_host` | HTTP `Host` is not `sandbox.*` / `dev.*` and not in `SANDBOX_VIRTUAL_BANK_EXTRA_PREPEND_HOSTS`. Example: `api.example.com` while the app uses sandbox tokens — use the sandbox API hostname or add your LAN/staging host to the env var. |
| `skipped_country_id_not_ru` | Request has `country_id` set to a country other than the configured receipt country. Omit `country_id` or use that country’s id (`receipt_country_code` / `receipt_country_id` in the same log line). |
| `skipped_no_ru_country` | No `countries` row whose `code` (trimmed, case-insensitive) equals `SANDBOX_VIRTUAL_BANK_COUNTRY_CODE` (default `RUS`). |
| `appended` / `already_present` | Virtual bank is in the JSON at the **end** of the list (`virtual_in_response` should be true). |

## Behaviour

- Merged into bank list responses for the country whose `countries.code` matches **`SANDBOX_VIRTUAL_BANK_COUNTRY_CODE`** (default `RUS`) when the request host matches the rules above.
- Lots use `virtual_sandbox_bank_code` in the database (`bank_id` stays `null`). The API still exposes a stable **`bank_id`** for clients (see below).
- After **bank payment details are sent** for the virtual sandbox bank, the API sends a synthetic **PDF receipt** to the counterparty notification email from the lot. This applies both to the regular first leg (`send payment details`) and to the reverse second leg when the buyer sends bank destination details (`buyer_destination_details`):
  - if `transactions.payment_details_set_by = seller` -> recipient is `exchange_lots.buyer_notification_email`;
  - if `transactions.payment_details_set_by = buyer` -> recipient is `exchange_lots.seller_notification_email`.
- Receipt recipient is determined by deal side (who set payment details), not by mailbox discovery.
- **DKIM / sender checks:** documented in code via `SandboxVirtualBankService` and `PaymentVerificationController::verifySender` (virtual id + optional `X-XBoard-Sandbox-Test-Bank-Receipt` header).

## Configuration

| Source | Key | Description |
|--------|-----|-------------|
| `config/sandbox_virtual_bank.php` | `virtual_bank_id` | From env `SANDBOX_VIRTUAL_BANK_ID`, default `900000001`. |
| `config/sandbox_virtual_bank.php` | `website_host` | From env `SANDBOX_VIRTUAL_BANK_WEBSITE`, default `sandbox-receipt.xdexboard.com` (hostname only, no scheme). |
| `config/sandbox_virtual_bank.php` | `country_code` | From env `SANDBOX_VIRTUAL_BANK_COUNTRY_CODE`, default `RUS`; must match a row in `countries`. |
| `config/sandbox_virtual_bank.php` | `supported_fiat_codes` | Fiat ISO codes for the synthetic bank (default `USD`, `EUR`); exposed as `supported_fiat_codes` in bank JSON. |

### Environment variables (optional)

If unset, defaults from `config/sandbox_virtual_bank.php` apply.

| Variable | Purpose |
|----------|---------|
| `SANDBOX_VIRTUAL_BANK_WEBSITE` | Hostname shown in the synthetic PDF and API field `website_host` (e.g. `sandbox-receipt.xdexboard.com`). |
| `SANDBOX_VIRTUAL_BANK_ID` | Integer returned as `id` in bank JSON and expected as `bank_id` when creating/updating a **test** lot on sandbox. |
| `SANDBOX_VIRTUAL_BANK_COUNTRY_CODE` | Receipt-bank country: must match `countries.code` (e.g. `RUS`). Default `RUS` if unset. |
| `SANDBOX_VIRTUAL_BANK_EXTRA_PREPEND_HOSTS` | Comma-separated extra `Host` values (case-insensitive) that get the same virtual-bank behaviour as `sandbox.*` / `dev.*` (e.g. `192.168.1.10` for phone → PC LAN API). Do not set to a production hostname unless that instance is sandbox-only. |

Example (commented) lines: see repository **`.env.example`**.

## Related

- XRPChange-specific env file: `docs/ENV_XRPCHANGE.md`.
- Production logging baseline: `docs/PRODUCTION_LOGGING_PROFILE.md`.

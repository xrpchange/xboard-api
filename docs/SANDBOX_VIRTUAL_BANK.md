# Sandbox virtual receipt bank (Russia only)

Synthetic bank entry for **sandbox / dev API hosts only** (`sandbox.*`, `dev.*`). It is **not** stored in the `banks` table.

## Behaviour

- Merged into **Russia (`RU`)** bank list responses when the request host is sandbox/dev.
- Lots use `virtual_sandbox_bank_code` in the database (`bank_id` stays `null`). The API still exposes a stable **`bank_id`** for clients (see below).
- After **send payment details** (bank flow), the API can email a **PDF receipt** to `PAYMENT_VERIFICATION_EMAIL` (first address if comma-separated).
- **DKIM / sender checks:** documented in code via `SandboxVirtualBankService` and `PaymentVerificationController::verifySender` (virtual id + optional `X-XBoard-Sandbox-Test-Bank-Receipt` header).

## Configuration

| Source | Key | Description |
|--------|-----|-------------|
| `config/sandbox_virtual_bank.php` | `virtual_bank_id` | From env `SANDBOX_VIRTUAL_BANK_ID`, default `900000001`. |
| `config/sandbox_virtual_bank.php` | `website_host` | From env `SANDBOX_VIRTUAL_BANK_WEBSITE`, default `sandbox-receipt.xdexboard.com` (hostname only, no scheme). |

### Environment variables (optional)

If unset, defaults from `config/sandbox_virtual_bank.php` apply.

| Variable | Purpose |
|----------|---------|
| `SANDBOX_VIRTUAL_BANK_WEBSITE` | Hostname shown in the synthetic PDF and API field `website_host` (e.g. `sandbox-receipt.xdexboard.com`). |
| `SANDBOX_VIRTUAL_BANK_ID` | Integer returned as `id` in bank JSON and expected as `bank_id` when creating/updating a **test** lot on sandbox. |

Example (commented) lines: see repository **`.env.example`**.

## Related

- `PAYMENT_VERIFICATION_EMAIL` — recipient for the sandbox PDF receipt mail (`config/mail.php` → `mail.verification_email`).
- XRPChange-specific env file: `docs/ENV_XRPCHANGE.md`.

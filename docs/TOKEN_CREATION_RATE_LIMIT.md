# Escrow token creation rate limit

**Purpose:** Protect against bots and abuse. Combined per-email (monthly) and per-IP (daily) limits for token creation; separate limits for requesting verification codes.

## Limits (token creation — POST /create)

| Limit        | Production | Sandbox | Config key |
|-------------|------------|---------|------------|
| Per email   | 10/month   | 30/month| `RATE_LIMIT_TOKEN_EMAIL_MONTH_PROD`, `RATE_LIMIT_TOKEN_EMAIL_MONTH_SANDBOX` |
| Per IP      | 20/day     | 20/day  | `RATE_LIMIT_TOKEN_IP_DAY` |

- Environment is derived from request host: `sandbox.*` / `dev.*` → sandbox; otherwise production.
- Home use expected; shared IPs (office, NAT) may hit the IP limit.

## Limits (verification code request — POST /request-verification-code)

| Limit           | Default | Config key |
|----------------|---------|------------|
| Per IP / hour  | 10      | `RATE_LIMIT_VERIFICATION_CODE_IP_PER_HOUR` |
| Per email / hour | 5    | `RATE_LIMIT_VERIFICATION_CODE_EMAIL_PER_HOUR` |

- Prevents anyone from spamming codes to a victim address or burning mail quota.
- Counters are stored in cache (key includes calendar hour); no DB tables.
- **429** with `error_code`: `verification_code_ip_limit_exceeded` or `verification_code_email_limit_exceeded`.

## Allowed email providers

An email is accepted if its **domain** is either:

1. **In `email_providers`** — active user providers (Gmail, Yandex, etc.). The client shows this list in Settings → Email Access.
2. **Sender domain** — the domain of `mail.from.address` (e.g. noreply@xdexboard.com → xdexboard.com). This is the server/own domain and is allowed programmatically; it is not stored in `email_providers`.

Other addresses receive `400 email_provider_not_supported`.

## API

- **POST /v1/escrow-tokens/request-verification-code**  
  Body: `{ "email": "user@example.com" }`. Email must be from a supported provider. Rate limited per IP (per hour) and per email (per hour). Returns 429 if limit exceeded.
- **POST /v1/escrow-tokens/create**  
  Body must include `email` (required). The email is used for quota and must belong to a supported provider.
- **429** with `error_code`: `email_quota_exceeded`, `ip_quota_exceeded`, `verification_code_ip_limit_exceeded`, or `verification_code_email_limit_exceeded`.
- **400** with `error_code`: `email_provider_not_supported` if the email domain is neither in `email_providers` nor the sender domain from config.

## Database

- **token_creation_quota:** `email`, `period_month` (YYYY-MM), `environment`, `count`.
- **token_creation_ip_daily:** `ip_address`, `date`, `count`.
- **email_providers.domain:** Primary domain for each provider (e.g. gmail.com); used to allow only supported providers.

## Config

- `config/rate_limit.php` — reads from env or defaults above.
- After changing limits: no restart needed; config is read per request.

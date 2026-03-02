# Escrow token creation rate limit

**Purpose:** Protect against bots and abuse. Combined per-email (monthly) and per-IP (daily) limits.

## Limits

| Limit        | Production | Sandbox | Config key |
|-------------|------------|---------|------------|
| Per email   | 10/month   | 30/month| `RATE_LIMIT_TOKEN_EMAIL_MONTH_PROD`, `RATE_LIMIT_TOKEN_EMAIL_MONTH_SANDBOX` |
| Per IP      | 20/day     | 20/day  | `RATE_LIMIT_TOKEN_IP_DAY` |

- Environment is derived from request host: `sandbox.*` / `dev.*` → sandbox; otherwise production.
- Home use expected; shared IPs (office, NAT) may hit the IP limit.

## Allowed email providers

An email is accepted if its **domain** is either:

1. **In `email_providers`** — active user providers (Gmail, Yandex, etc.). The client shows this list in Settings → Email Access.
2. **Sender domain** — the domain of `mail.from.address` (e.g. noreply@xdexboard.com → xdexboard.com). This is the server/own domain and is allowed programmatically; it is not stored in `email_providers`.

Other addresses receive `400 email_provider_not_supported`.

## API

- **POST /api/escrow-tokens/create**  
  Body must include `email` (required). The email is used for quota and must belong to a supported provider.
- **429** with `error_code`: `email_quota_exceeded` or `ip_quota_exceeded`.
- **400** with `error_code`: `email_provider_not_supported` if the email domain is neither in `email_providers` nor the sender domain from config.

## Database

- **token_creation_quota:** `email`, `period_month` (YYYY-MM), `environment`, `count`.
- **token_creation_ip_daily:** `ip_address`, `date`, `count`.
- **email_providers.domain:** Primary domain for each provider (e.g. gmail.com); used to allow only supported providers.

## Config

- `config/rate_limit.php` — reads from env or defaults above.
- After changing limits: no restart needed; config is read per request.

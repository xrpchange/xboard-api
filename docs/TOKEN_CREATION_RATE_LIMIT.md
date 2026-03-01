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

Only emails whose **domain** is present in `email_providers.domain` (active providers) are accepted. Other addresses receive `400 email_provider_not_supported`. The client shows the list of supported providers in Settings → Email Access and warns that other mail services are not supported.

## API

- **POST /api/escrow-tokens/create**  
  Body must include `email` (required). The email is used for quota and must belong to a supported provider.
- **429** with `error_code`: `email_quota_exceeded` or `ip_quota_exceeded`.
- **400** with `error_code`: `email_provider_not_supported` if the email domain is not in `email_providers`.

## Database

- **token_creation_quota:** `email`, `period_month` (YYYY-MM), `environment`, `count`.
- **token_creation_ip_daily:** `ip_address`, `date`, `count`.
- **email_providers.domain:** Primary domain for each provider (e.g. gmail.com); used to allow only supported providers.

## Config

- `config/rate_limit.php` — reads from env or defaults above.
- After changing limits: no restart needed; config is read per request.

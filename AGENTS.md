# AGENTS.md

XBoard API (PHP/Laravel).

**IMPORTANT: Read this file first if you have memory issues or are unsure about the project structure!**

**`xrpchange.site` is out of scope** unless the user explicitly asks in the current chat — see `.cursor/rules/xrpchange-out-of-scope.mdc`. Default API hosts: **`*.xdexboard.com`** with **`.env`** on this PC.

**Деплой на сервер:** только **git push** + `scripts/server-git-pull-xboard-api.sh` на `server@xdexboard.com`. **Не** копировать PHP через `scp` — см. `.cursor/rules/git-only-server-deploy.mdc`.

Start by reading:

- README.md — service purpose, local setup, deployment
- `./docs/` folder — detailed information about endpoints, database schemas, and integrations
- **`docs/ECOSYSTEM_CANONICAL_BASELINE.md`** — ecosystem rollback tag `canonical-2026-06-04` (stable baseline across all repos)

Key points:
- **Public URLs are HTTPS-only:** no `http://` in mail or browser links; do not add in-app http→https redirect middleware (breaks behind TLS terminators). See `.cursor/rules/https-only-public-urls.mdc`.
- **No localized texts on the server.** All user-facing strings are localized in the client (xboard-client). The API must not contain locale-dependent text (no translation arrays/maps in PHP). See `.cursor/rules/no-localized-texts-on-server.mdc`.
- Backend: PHP 8+, Laravel. Configuration via `.env` and `config/*.php`, no hardcoded `127.0.0.1`.
- **Database time:** Treat all persisted timestamps as **UTC**. `APP_TIMEZONE` must stay UTC; MySQL/MariaDB use `SET time_zone='+00:00'` on connect (`config/database.php`). Do not write local-wall-clock values into `datetime`/`timestamp` columns without converting to UTC first.
- **Ecosystem UTC:** The same rule applies across sibling repos: **xboard-core** sets `date_default_timezone_set('UTC')` at `public/api.php` bootstrap; **xboard-monero-balance** sets `time.Local = time.UTC` in service CLIs; **xboard-client** treats API datetimes as instants (ISO-8601 UTC) and aligns deal timers to server time from chat responses.
- **`.env.xrpchange`:** gitignored; **local XAMPP only** — simulates a second exchange (`api.xrpchange.site`). Not production scope; see `docs/ENV_XRPCHANGE.md`. `APP_ENV=xrpchange` via Apache `SetEnv` only — no `HTTP_HOST` fallback.
- `nodes` table — source of available nodes for clients; keep migrations and schema up to date (including `weight`, `max_connections` if applicable).
- **Browser web client (CORS):** Laravel’s default `HandleCors` is **removed** in `bootstrap/app.php`; CORS only at Apache/Nginx — **`docs/CORS_WEB_CLIENT.md`**. Exactly one layer must emit `Access-Control-*`.
- **Partner IP lookup URL:** `IP_LOOKUP_URL_TEMPLATE` in `.env` (HTTPS template with `{ip}`) is exposed to clients in `GET /api/v1/nodes` and `GET /api/v1/nodes/required` response `meta.ip_lookup_url_template`. See `docs/IP_LOOKUP_URL_TEMPLATE.md`.

## Терминология для партнёров и документации (Business terminology)

В документации для партнёров и в пользовательских текстах используйте бизнес-понятия:
- **Обменник** (EN: Exchange) — один пункт обмена; в коде/API: node (запись в таблице `nodes`).
- **Сеть обменников** (EN: Exchange network) — набор доверенных обменников; источник данных — таблица `nodes`. Не использовать «кластер».
- **Клиент** (EN: Client) — приложение для пользователей и партнёров (Android, Web и т.д.), подключающееся к API обменников.

Подробнее: `docs/TERMINOLOGY.md`.
- Integrations:
  - XBoard Core — business operations for tokens/escrow
  - **Deal AML:** Core screens only; API persists `transactions.aml_check_json`; provider failures → `AML_ADMIN_ALERT_EMAIL` or Core `admin_email` — **`docs/AML_DEAL_PERSISTENCE.md`**
  - Monero Balance Service — proxy for XMR balance checks (no "fake" data)
  - `smalot/pdfparser` (LGPL-3.0) — server-side extraction of plain text from bank receipt PDFs for the AI arbiter prompt (`docs/AI_ARBITER_SERVER.md`).
- Logs — no secrets.
- Any API changes must be accompanied by updates to README and relevant documents in `docs/`.
- **Spec-driven tests:** behaviors described in docs should map to automated checks via **`docs/SPEC_REGISTRY.md`** (requirement ID → test). Add a registry row and a `@group spec` / `@group spec-<ID>` test when you document new rules; run `php artisan test --group spec`.

## Email Operations

**CRITICAL: Use webklex/php-imap library for email operations!**

- Self-hosted Postfix (e.g. `mail.xdexboard.com`): relay hardening, queue limits, and incident response — **`docs/POSTFIX_RELAY_AND_QUEUE.md`**.
- The project uses `webklex/php-imap` package from Composer for IMAP operations
- **For sending emails:** Use email service API with token (Postmark, Mailgun, Resend, etc.) configured in `config/services.php` and `config/mail.php`
- **DO NOT** use SMTP with password authentication - use API tokens instead
- **DO NOT** implement custom SMTP socket connections - use Laravel Mail with configured mailer
- Always check existing code and documentation before implementing new email functionality
- **Supported mail domains (`email_providers`):** canonical list in `database/seeders/EmailProviderSeeder.php`; load with `php artisan db:seed` (or `--class=EmailProviderSeeder`). Client list = `is_active`; escrow email allowlist = any row with matching `domain`. See `docs/TOKEN_CREATION_RATE_LIMIT.md`.

## Development Workflow

1. **Before writing code:**
   - Read relevant documentation in `./docs/`
   - Check existing implementations in the codebase
   - Review `AGENTS.md` for project-specific guidelines
   - Test your approach in a test script before implementing

2. **When implementing email features:**
   - Use `webklex/php-imap` for IMAP operations (reading emails)
   - Use Laravel Mail with configured mailer (Postmark/Mailgun/Resend) for sending emails
   - Never hardcode user-facing email addresses in PHP — use config / env (e.g. `MAIL_FROM_ADDRESS`, `config('mail.from.address')`) where appropriate.
   - Preserve DKIM signatures when forwarding raw emails

3. **After implementation:**
   - Update documentation in `docs/` folder
   - Update README.md if needed
   - Test thoroughly before committing

## Directory Structure

**Test Scripts:**
- `temp/` - Temporary test scripts for development and debugging
  - Use for quick tests and experiments
  - Scripts may be removed or modified at any time
  - Example: `temp/test_get_message_by_uid.php`

**Utility Scripts:**
- `scripts/` - Useful utility scripts for the project
  - Production-ready scripts for deployment and maintenance
  - Example: `scripts/test_email_forwarding.php` - Test email forwarding via API
  - Example: `scripts/email-server/` - Email server setup scripts

**Important:** 
- Do NOT create test scripts in project root
- Use `temp/` for temporary test scripts
- Use `scripts/` for useful utility scripts

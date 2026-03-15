# AGENTS.md

XBoard API (PHP/Laravel).

**IMPORTANT: Read this file first if you have memory issues or are unsure about the project structure!**

Start by reading:

- README.md — service purpose, local setup, deployment
- `./docs/` folder — detailed information about endpoints, database schemas, and integrations

Key points:
- **No localized texts on the server.** All user-facing strings are localized in the client (xboard-client). The API must not contain locale-dependent text (no translation arrays/maps in PHP). See `.cursor/rules/no-localized-texts-on-server.mdc`.
- Backend: PHP 8+, Laravel. Configuration via `.env` and `config/*.php`, no hardcoded `127.0.0.1`.
- **NO fallback by host on server:** For `.env.xrpchange` (api.xrpchange.site), `APP_ENV` MUST be set explicitly via Apache `SetEnv` (or equivalent). Do NOT add fallback detection by `HTTP_HOST` or any other implicit method on production.
- `nodes` table — source of available nodes for clients; keep migrations and schema up to date (including `weight`, `max_connections` if applicable).

## Терминология для партнёров и документации (Business terminology)

В документации для партнёров и в пользовательских текстах используйте бизнес-понятия:
- **Обменник** (EN: Exchange) — один пункт обмена; в коде/API: node (запись в таблице `nodes`).
- **Сеть обменников** (EN: Exchange network) — набор доверенных обменников; источник данных — таблица `nodes`. Не использовать «кластер».
- **Клиент** (EN: Client) — приложение для пользователей и партнёров (Android, Web и т.д.), подключающееся к API обменников.

Подробнее: `docs/TERMINOLOGY.md`.
- Integrations:
  - XBoard Core — business operations for tokens/escrow
  - Monero Balance Service — proxy for XMR balance checks (no "fake" data)
- Logs — no secrets.
- Any API changes must be accompanied by updates to README and relevant documents in `docs/`.

## Email Operations

**CRITICAL: Use webklex/php-imap library for email operations!**

- The project uses `webklex/php-imap` package from Composer for IMAP operations
- **For sending emails:** Use email service API with token (Postmark, Mailgun, Resend, etc.) configured in `config/services.php` and `config/mail.php`
- **DO NOT** use SMTP with password authentication - use API tokens instead
- **DO NOT** implement custom SMTP socket connections - use Laravel Mail with configured mailer
- Always check existing code and documentation before implementing new email functionality

## Development Workflow

1. **Before writing code:**
   - Read relevant documentation in `./docs/`
   - Check existing implementations in the codebase
   - Review `AGENTS.md` for project-specific guidelines
   - Test your approach in a test script before implementing

2. **When implementing email features:**
   - Use `webklex/php-imap` for IMAP operations (reading emails)
   - Use Laravel Mail with configured mailer (Postmark/Mailgun/Resend) for sending emails
   - Never hardcode email addresses - use `PAYMENT_VERIFICATION_EMAIL` from `.env`
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

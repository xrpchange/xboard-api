# XBoard - P2P Cryptocurrency Exchange with Collateral Guarantee

<p align="center">
<img src="https://img.shields.io/badge/Laravel-10.x-red.svg" alt="Laravel Version">
<img src="https://img.shields.io/badge/PHP-8.4-blue.svg" alt="PHP Version">
<img src="https://img.shields.io/badge/MySQL-8.0+-orange.svg" alt="MySQL Version">
<img src="https://img.shields.io/badge/SSL-Let's%20Encrypt-green.svg" alt="SSL Certificate">
<img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License">
</p>

## 🎯 Project Description

XBoard is a P2P cryptocurrency exchange (bulletin board) with collateral guarantee, using Ripple (XRP) for collateral tokens with the ability to expand to other blockchains.

### 🏗️ System Architecture

The system consists of two main components:

- **XBoard** - Public API for P2P exchange bulletin board
- **XRPChangeCore** - Core system for blockchain operations and collateral tokens

📋 **[Complete System Overview → PROJECT_OVERVIEW.md](PROJECT_OVERVIEW.md)**

### 🔑 Core Principles

1. **Collateral tokens as identifiers** - The collateral token itself is the access token to the exchange board, no user registration required
2. **Sandbox** - Testing mode, switchable on-the-fly in mobile application
3. **External transactions** - Transactions are not conducted on the platform, information about them comes from users
4. **Security** - Special commissions and reservations are stored in a separate database on a physically separate server

### 🌐 System Domains

**XBoard API:**
- Sandbox: `https://sandbox.api.xdexboard.com`
- Production: `https://api.xdexboard.com`

**XRPChangeCore API:**
- Sandbox: `https://sandbox.api.core.xdexboard.com`
- Production: `https://api.core.xdexboard.com`

## Quick Start

### Requirements
- PHP 8.4+
- MySQL 8.0+
- Composer
- Nginx (for production)
- SSL certificates (Let's Encrypt)

### Installation

1. **Clone repository**
```bash
git clone https://github.com/your-username/xboard.git
cd xboard
```

2. **Install dependencies**
```bash
composer install
npm install
```

3. **Environment setup**
```bash
cp .env.example .env
php artisan key:generate
```

**Required environment variables:**
- **Optional (sandbox virtual receipt bank):** `SANDBOX_VIRTUAL_BANK_WEBSITE`, `SANDBOX_VIRTUAL_BANK_COUNTRY_CODE`, `SANDBOX_VIRTUAL_BANK_ID` — documented in [docs/SANDBOX_VIRTUAL_BANK.md](docs/SANDBOX_VIRTUAL_BANK.md); commented examples in `.env.example`.
- **Core API key** — `CORE_KEY` (sandbox) and `CORE_PRODUCTION_KEY` (production). Required for API-only Core endpoints (token creation, delete reservation). Must match Core config `[api] CORE_API_KEY`. See [docs/CORE_API_KEY_AND_API_ONLY_ENDPOINTS.md](docs/CORE_API_KEY_AND_API_ONLY_ENDPOINTS.md).

**Client mode configuration:**
- `APP_DEFAULT_ENVIRONMENT` - Default environment mode suggested to client applications (default: `sandbox`)
  - Values: `production` or `sandbox`
  - This setting is sent in `/api/v1/nodes` endpoint response as `meta.default_environment`
  - **Purpose:** This is a "suggested mode" feature for first-time project launch and user onboarding
  - **Use case:**
    - **Initial launch (testing phase):** Set to `sandbox` to automatically enable Developer Mode and switch to Sandbox API for testers who won't configure settings manually
    - **After testing phase (production):** Switch to `production` - users who haven't manually configured settings will automatically switch to Production mode
    - **Target users:** Non-technical users who won't explore settings - the app configures itself based on server suggestion
  - **How it works:**
    - If set to `sandbox`: Client app automatically enables Developer Mode and switches to Sandbox API endpoint (only if user hasn't configured settings)
    - If set to `production`: Client app uses Production mode by default (Developer Mode disabled, only if user hasn't configured settings)
  - **Server management:** Server fully manages app mode (Developer Mode and API endpoint) **only if user hasn't configured settings**
  - **User priority:** Once user manually configures Developer Mode or API endpoint, server suggestions are ignored for that setting
  - **Migration:** After testing phase, change to `production` - users who haven't configured settings will automatically switch to Production
  - **Configuration:** Set in `.env` file: `APP_DEFAULT_ENVIRONMENT=production` or `APP_DEFAULT_ENVIRONMENT=sandbox`
  - **Default value:** `sandbox` (configured in `config/app.php`) - for initial testing phase
  - **Migration path:** After testing phase, change to `production` in `.env` or `config/app.php` - users who haven't configured settings will automatically switch to Production

**Seller IP lookup (optional partner URL):**
- `IP_LOOKUP_URL_TEMPLATE` — HTTPS URL template with `{ip}` placeholder; returned in `GET /api/v1/nodes` → `meta.ip_lookup_url_template` for the client’s “lookup ISP / network” action when the buyer opted in to share their IP.
- See [docs/IP_LOOKUP_URL_TEMPLATE.md](docs/IP_LOOKUP_URL_TEMPLATE.md).

**Email service configuration:**
- Email sending uses API token-based services (Postmark/Resend/Mailgun), not SMTP with password
- Configure `MAIL_MAILER` in `.env` (e.g., `MAIL_MAILER=postmark`)
- Configure API token in `.env`:
  - For Postmark: `POSTMARK_TOKEN=your_token`
  - For Resend: `RESEND_KEY=your_key`
  - For Mailgun: Configure in `config/services.php`
- **IMAP operations** use `webklex/php-imap` library (already in composer.json) for reading emails via OAuth
- `MAIL_TEST_EMAIL_DAILY_LIMIT` (default `30`) limits external emails per recipient/day. It is reused by:
  - seller/buyer external deal notifications,
  - fee-wallet verification code flow (`request-fee-wallet-verification-code`).

**Lot mailbox (IMAP chat for exchange lots):**
- See [docs/LOT_MAILBOX_AND_IMAP_CHAT.md](docs/LOT_MAILBOX_AND_IMAP_CHAT.md) for full documentation
- `LOT_MAILBOX_DOMAIN` — domain for lot mailboxes (default: `xdexboard.com`)
- `LOT_MAILBOX_IMAP_HOST` — IMAP host for clients (default: `mail.xdexboard.com`)
- `LOT_MAILBOX_IMAP_PORT` — IMAP port (default: `993`)
- `LOT_MAILBOX_IMAP_SSL` — use SSL for IMAP (default: `true`)
- `LOT_MAILBOX_MAILDIR_BASE` — Maildir base path on mail server (default: `/var/mail/vhosts`)

4. **Database setup**
```bash
# Edit .env file with your database settings
php artisan migrate
php artisan db:seed
```

- **`email_providers`:** заполняется `EmailProviderSeeder` (входит в `db:seed`). Обновить только эту таблицу: `php artisan db:seed --class=EmailProviderSeeder`. Список для клиента (`GET /v1/email-providers`) — строки с `is_active = true`; белый список доменов для email при создании escrow-токена — любая строка с совпадающим `domain` (в т.ч. неактивные, например `gmail.com`). Подробнее: [docs/TOKEN_CREATION_RATE_LIMIT.md](docs/TOKEN_CREATION_RATE_LIMIT.md).

- Для уже существующих БД: миграция справочника `crypto_currencies` (синхронизация с Core) описана в [docs/CRYPTO_CURRENCIES_MIGRATION.md](docs/CRYPTO_CURRENCIES_MIGRATION.md). Пользователю БД нужны права `ALTER` на таблицу.

5. **XRPChangeCore setup**
```bash
# Configure .env.sandbox in XRPChangeCore
# Ensure core is accessible at core API address
```

6. **Start server**
```bash
php artisan serve
```

Application will be available at: http://localhost:8000

### 🚀 Production Deployment

1. **Nginx with SSL setup**
```bash
# Follow instructions in NGINX_SSL_SETUP.md
```

2. **XRPChangeCore setup**
```bash
# Follow instructions in CORE_NGINX_SETUP.md
```

3. **Cron jobs setup**
```bash
# Follow instructions in CRON_SSL_SETUP.md
```

## API Documentation

### Swagger UI
After starting the server, documentation is available at:
- **API**: http://localhost:8000/api/documentation
- **Core API**: http://localhost:8000/docs/core_swagger.yaml

### API Documentation:
- **Sandbox** (for testing): https://sandbox.api.xdexboard.com/documentation
- **Production**: https://api.xdexboard.com/documentation
- **Local**: http://localhost:8000/api/documentation

### Main Endpoints

#### Authentication
- Escrow token authentication (X-Escrow-Token header)
- `GET /api/v1/auth/validate` - Validate escrow token

#### Reference Data (public)
- `GET /api/v1/countries` - List of countries
- `GET /api/v1/currencies/fiat` - Fiat currencies
- `GET /api/v1/currencies/crypto` - Cryptocurrencies
- `GET /api/v1/banks` - List of banks

#### Exchange Board (protected)
- `GET /api/v1/exchange-lots` - List of lots
- `POST /api/v1/exchange-lots` - Create lot
- `PUT /api/v1/exchange-lots/{id}` - Update lot
- `DELETE /api/v1/exchange-lots/{id}` - Delete lot

#### Escrow Tokens (protected utility endpoints)
- `POST /api/v1/escrow-tokens/request-fee-wallet-verification-code` - Send one-time code to email for fee wallet update
- `POST /api/v1/escrow-tokens/set-fee-wallet-address` - Set one Core `*_fee_wallet_clean` / `*_fee_wallet_dirty` value
- `POST /api/v1/escrow-tokens/get-crypto-fee-params` - Read Core merged fee scalars for a `crypto_key` (rates, min/max fees, AML reserve); body: `admin_mode_password`, `crypto_key`; header `X-Escrow-Token`
- `POST /api/v1/escrow-tokens/set-crypto-fee-params` - Update those scalars in Core INI after the same mailbox verification flow as fee wallet; body: `email`, `verification_code`, `admin_password`, `crypto_key`, `params` (map of allowed suffixes → numbers)

**Administrator mode (client Developer settings):** `admin_mode_password` (Core) unlocks the client and gates disclosure such as **`admin_email`**; it does **not** replace **email verification code + `admin_password`** for mutating Core settings. See **`docs/ADMIN_MODE_SECURITY.md`**.

### Fee Wallet Admin Flow (clean / dirty)

This flow allows updating Core fee wallet destination parameters from the client without direct server file access.

1. Client sends `POST /api/v1/escrow-tokens/request-fee-wallet-verification-code` with:
   - connected mailbox email,
   - PoW puzzle solution (`puzzle_token` + `puzzle_answer`),
   - `X-Escrow-Token` header.
2. API validates limits and sends mailbox code (reuses `MAIL_TEST_EMAIL_DAILY_LIMIT` per recipient/day).
3. Client sends `POST /api/v1/escrow-tokens/set-fee-wallet-address` with:
   - `email`,
   - `verification_code`,
   - `admin_password`,
   - `wallet_param` (only `*_fee_wallet_clean` or `*_fee_wallet_dirty`),
   - `wallet_address` (empty string means clear),
   - `X-Escrow-Token`.
4. API resolves Core by token environment and calls Core `POST /api/admin/set-fee-wallet` with `X-Core-Key`.

Notes:
- Monero (`xmr_*`) fee wallet params are intentionally rejected in this flow.
- API does not localize messages; client localization applies as usual.

#### Buy Triggers (protected)
- `GET /api/v1/buy-triggers` - List of triggers
- `POST /api/v1/buy-triggers` - Create trigger
- `PUT /api/v1/buy-triggers/{id}` - Update trigger
- `DELETE /api/v1/buy-triggers/{id}` - Delete trigger

#### Transactions (protected)
- `GET /api/v1/transactions` - List of transactions
- `GET /api/v1/transactions/stats` - Transaction statistics
- `POST /api/v1/transactions` - Create transaction
- `PUT /api/v1/transactions/{id}` - Update transaction

## Architecture

### Backend
- **Framework**: Laravel 10.x
- **Database**: MySQL 8.0+
- **Time**: `APP_TIMEZONE` is UTC. Each MySQL/MariaDB connection sets `time_zone` to `+00:00` so session `NOW()` / `CURRENT_TIMESTAMP` and Laravel’s datetime casting align with **UTC storage** (see `config/database.php`). Persisted instants are always UTC; local time is only for display or domain-specific fields (e.g. bank time + IANA tz) normalized before storage. Same **UTC-first** convention is documented for **xboard-core**, **xboard-monero-balance**, and **xboard-client** (`AGENTS.md` and/or README).
- **API**: REST API with Escrow token authentication
- **Documentation**: Swagger/OpenAPI 3.0

### Frontend
- **Android**: Kotlin - [Client Documentation](docs/CLIENT_README.md)
- **iOS**: Swift (planned)
- **Web**: Vue.js/React (planned)

### Core System
- **Separate service**: XRPChangeCore
- **Blockchain**: XRP Ledger
- **Security**: Physically separate equipment

#### Core health check (in core itself)
In `XRPChangeCore/public/echo.php` there should be an echo endpoint (default `/echo.php`), which XBoard health check uses. Path can be overridden via environment variable `CORE_HEALTH_PATH`.

## Database Structure

### Reference Data
- `countries` - Countries (ISO 3166-1 alpha-3)
- `fiat_currencies` - Fiat currencies (ISO 4217)
- `crypto_currencies` - Cryptocurrencies
- `banks` - Banks (linked to countries)

### Main Tables
- `exchange_lots` - Exchange board
- `buy_triggers` - Buy triggers
- `transactions` - External transactions

### Features
- Test mode support (`is_test`, `test_group`)
- Indexes for fast search
- Critical data encryption

## Testing

PHPUnit is configured for **MySQL** (see `phpunit.xml`: `DB_DATABASE=xboard_api_test` by default). Create an empty database before the first run, for example:

```sql
CREATE DATABASE xboard_api_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Adjust `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` in `phpunit.xml` or export them in the shell if your local MySQL differs.

### Run tests
```bash
php artisan test
```

**Spec-linked tests** (documented behavior → PHPUnit; see `docs/SPEC_REGISTRY.md`):

```bash
composer test-spec
# or: php artisan test --group spec
```

### Test mode
In test mode, all requests must contain `test_group` for data isolation.

## Security

### Collateral tokens
- Use XRP for fast transactions
- Contain encrypted information
- Stored only in core system

### API Security
- Escrow token authentication (X-Escrow-Token header)
- Validation of all input data
- DDoS attack protection

## Development

### Artisan Commands
```bash
# Migrations
php artisan migrate
php artisan migrate:fresh --seed

# Clear cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Generate documentation
php artisan l5-swagger:generate
```

### Project Structure
```
xboard/
├── app/
│   ├── Http/Controllers/Api/    # API controllers
│   └── Models/                  # Eloquent models
├── database/
│   ├── migrations/              # Database migrations
│   └── seeders/                # Data seeders
├── docs/                       # Documentation
│   ├── api_swagger.yaml        # API documentation
│   └── core_swagger.yaml       # Core documentation
└── routes/
    └── api.php                 # API routes
```

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 📚 Documentation

### System Documentation
- **[System Overview](PROJECT_OVERVIEW.md)** - Complete architecture and components
- **[API Compatibility Audit](API_COMPATIBILITY_AUDIT.md)** - Fork readiness and long-term compatibility
- **[Nginx + SSL Setup](NGINX_SSL_SETUP.md)** - XBoard deployment instructions
- **[XRPChangeCore Setup](CORE_NGINX_SETUP.md)** - Core system deployment instructions
- **[Cron Jobs Setup](CRON_SSL_SETUP.md)** - Automatic certificate renewal
- **[Email Server Setup](docs/EMAIL_SERVER_SETUP.md)** - Email server for payment verification (DKIM checking)
- **[Postfix relay & queue](docs/POSTFIX_RELAY_AND_QUEUE.md)** - Open relay, queue limits, `postsuper`, firewall (self-hosted SMTP)
- **[Scheduled Pruning](docs/SCHEDULED_PRUNING.md)** - Daily cleanup: `deal_chat_messages`, `receipt_file_checksums`, and `transactions` older than 15 days
- **[Lot Mailbox and IMAP Chat](docs/LOT_MAILBOX_AND_IMAP_CHAT.md)** - Концепция: почтовые ящики для лотов, чат на IMAP (не реализовано)
- **[Deal Dispute System](docs/DEAL_DISPUTE_SYSTEM.md)** - Separate dispute lifecycle state (`deal_disputes`) and API flow
- **[AI Arbiter (server)](docs/AI_ARBITER_SERVER.md)** - Single LLM provider (default **GigaChat**, no vendor failover), env, security, **per-lot invocation cap** (`exchange_lots.arbiter_ai_invocations`), `dispute-status` fields, curl test checklist
- **[Email Verification Methods](docs/EMAIL_VERIFICATION_METHODS.md)** - Complete guide for all email verification methods (OAuth, .eml, forward, copy-paste)
- **[User Email Verification Guide](docs/USER_EMAIL_VERIFICATION_GUIDE.md)** - User-friendly guide for verifying payment emails
- **[OAuth Setup Guide](docs/OAUTH_SETUP.md)** - OAuth integration setup for email providers (Yandex, Gmail, Outlook)

### Developer Documentation
- **Work Plan**: [docs/WORK_PLAN.md](docs/WORK_PLAN.md)
- **Android Client**: [docs/CLIENT_README.md](docs/CLIENT_README.md)
- **API Documentation**: [docs/api_swagger.yaml](docs/api_swagger.yaml)
- **Project Tasks**: [docs/task.md](docs/task.md)

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Core Access

Simple access to core (XRPChangeCore) via Laravel HTTP client:

### Core Configuration

`config/services.php` → `core` section:

- `url`: Core URL
- `key`: Authorization key (optional)
- `timeout`: HTTP request timeout (seconds)
- `payout_timeout`: Longer timeout for on-chain payout / AML routes

ENV example:

```env
CORE_URL=https://api.core.xdexboard.com
CORE_KEY=your_api_key
CORE_TIMEOUT=10
CORE_PAYOUT_TIMEOUT=120
```

### Usage Examples

```php
use Illuminate\Support\Facades\Http;

public function checkTokenBalance(string $token): array
{
    $coreUrl = config('services.core.url');
    $coreKey = config('services.core.key');
    $timeout = config('services.core.timeout', 10);

    $request = Http::timeout($timeout);
    
    if (!empty($coreKey)) {
        $request = $request->withHeaders([
            'X-Core-Key' => $coreKey,
        ]);
    }

    $response = $request->post($coreUrl . '/api/tokens/balance', [
        'token' => $token,
    ]);

    return $response->json();
}
```

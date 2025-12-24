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

4. **Database setup**
```bash
# Edit .env file with your database settings
php artisan migrate
php artisan db:seed
```

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
- `POST /api/v1/auth/bearer-token` - Get Bearer token
- `GET /api/v1/auth/validate` - Validate token

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
- **API**: REST API with Bearer tokens
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

### Run tests
```bash
php artisan test
```

### Test mode
In test mode, all requests must contain `test_group` for data isolation.

## Security

### Collateral tokens
- Use XRP for fast transactions
- Contain encrypted information
- Stored only in core system

### API Security
- Bearer tokens for authentication
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

ENV example:

```env
CORE_URL=https://api.core.xdexboard.com
CORE_KEY=your_api_key
CORE_TIMEOUT=10
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

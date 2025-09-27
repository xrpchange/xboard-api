# XBoard Project Overview

## 🎯 System Architecture

XBoard is a P2P cryptocurrency exchange platform with collateral guarantee system. The architecture consists of two main components:

### 1. XBoard (Main API)
- **Purpose**: Public API for P2P exchange board
- **Technology**: Laravel 10.x + PHP 8.4
- **Database**: MySQL 8.0+
- **Deployment**: Nginx + SSL (Let's Encrypt)

### 2. XRPChangeCore (Core System)
- **Purpose**: Blockchain operations and collateral token management
- **Technology**: PHP-based core system
- **Blockchain**: XRP Ledger (Ripple)
- **Security**: Physically separate server

## 🌐 Domain Structure

### XBoard Domains
- **Sandbox**: `sandbox.api.xrpchange.site`
- **Production**: `api.xrpchange.site`

### XRPChangeCore Domains
- **Sandbox**: `sandbox.api.core.xrpchange.site`
- **Production**: `api.core.xrpchange.site`

## 🔧 Current Infrastructure

### Server Setup
- **Main Server**: `192.168.1.31:80` (XBoard application)
- **Nginx**: Reverse proxy with SSL termination
- **SSL Certificates**: Let's Encrypt with auto-renewal
- **Cron Jobs**: Monero monitoring + SSL renewal

### SSL Configuration
- HTTP to HTTPS redirect
- HTTP/2 support
- Security headers (HSTS, X-Frame-Options, etc.)
- CORS headers for API access

## 📡 API Communication Flow

```
Client App → Nginx → XBoard API → XRPChangeCore
    ↓           ↓         ↓            ↓
HTTPS/SSL   Proxy    Laravel     Blockchain
           Headers    Logic        Logic
```

### Authentication Flow
1. Client gets collateral token from XRPChangeCore
2. Client sends token to XBoard `/api/v1/auth/bearer-token`
3. XBoard validates token with XRPChangeCore
4. XBoard returns Bearer token for API access

## 🗄️ Database Structure

### XBoard Database
- **Countries**: ISO 3166-1 alpha-3 codes
- **Currencies**: Fiat and crypto currencies
- **Banks**: Banking institutions by country
- **Exchange Lots**: P2P trading board
- **Buy Triggers**: Automated purchase conditions
- **Transactions**: External transaction records

### Key Features
- Test mode support (`is_test`, `test_group`)
- Optimized indexes for fast search
- Encrypted sensitive data

## 🔐 Security Model

### Collateral Tokens
- XRP-based for fast, cheap transactions
- Encrypted wallet and commission information
- No user registration required
- Mobile app exclusive token generation

### API Security
- Bearer token authentication
- Input validation on all endpoints
- DDoS protection
- CORS configuration

## 🚀 Deployment Status

### ✅ Completed
- Nginx configuration with SSL
- XBoard API endpoints
- XRPChangeCore integration
- SSL certificate auto-renewal
- Database setup and seeding
- Swagger documentation

### 🔄 Active Services
- XBoard API (Laravel)
- XRPChangeCore API
- Nginx reverse proxy
- SSL certificate management
- Cron job monitoring

## 📋 Configuration Files

### XBoard
- `.env` - Environment configuration
- `config/services.php` - Core service URLs
- `nginx-xboard.conf` - Nginx configuration
- `nginx-xboard-temp.conf` - Temporary config for SSL setup

### XRPChangeCore
- `.env.sandbox` - Sandbox environment
- `.env.prod` - Production environment
- `nginx-core.conf` - Nginx configuration
- `nginx-core-temp.conf` - Temporary config

### SSL Management
- `crontab_ssl.txt` - Cron configuration
- `CRON_SSL_SETUP.md` - Setup instructions

## 🛠️ Development Workflow

### Local Development
1. Clone XBoard repository
2. Install dependencies: `composer install`
3. Configure `.env` with database settings
4. Run migrations: `php artisan migrate --seed`
5. Start server: `php artisan serve`

### Production Deployment
1. Configure Nginx with temporary config
2. Obtain SSL certificates via Certbot
3. Replace with SSL-enabled configuration
4. Set up cron jobs for monitoring
5. Test API endpoints

## 📚 Documentation Structure

- `README.md` - Main project documentation
- `PROJECT_OVERVIEW.md` - This system overview
- `NGINX_SSL_SETUP.md` - Nginx and SSL setup guide
- `CORE_NGINX_SETUP.md` - Core system Nginx setup
- `CRON_SSL_SETUP.md` - Cron job configuration
- `docs/CLIENT_README.md` - Android client documentation
- `docs/api_swagger.yaml` - API documentation

## 🔍 Monitoring & Health Checks

### Health Check Endpoints
- XBoard: Standard Laravel health check
- XRPChangeCore: `/echo.php` endpoint

### Monitoring
- Monero node monitoring (every 5 minutes)
- SSL certificate renewal (daily at 2 AM)
- Nginx status monitoring

## 🎯 Key Integration Points

### XBoard → XRPChangeCore
- Token validation
- Balance checking
- Transaction processing
- Health monitoring

### Client → XBoard
- API authentication
- Exchange lot management
- Transaction history
- Real-time updates

## 🚨 Troubleshooting

### Common Issues
1. **Database Connection**: Check MySQL service and credentials
2. **SSL Certificate**: Verify Certbot configuration and renewal
3. **Core Integration**: Test XRPChangeCore connectivity
4. **CORS Issues**: Verify Nginx CORS headers

### Log Locations
- Nginx: `/var/log/nginx/`
- SSL: `/var/log/letsencrypt/`
- Laravel: `storage/logs/`
- Cron: `/var/log/cron`

This overview provides a comprehensive understanding of the XBoard system architecture, deployment, and operational status.

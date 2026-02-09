# XBoard - P2P Cryptocurrency Exchange with Collateral Guarantee

## Project Description

XBoard is a P2P cryptocurrency exchange (bulletin board) with collateral guarantee, using Ripple (XRP) for collateral tokens with the ability to expand to other blockchains.

### Basic Operating Principles

1. **Collateral tokens as identifiers** - the collateral token itself is the access token to the exchange board, no user registration required
2. **Sandbox** - testing mode, switchable on-the-fly in the mobile application
3. **External transactions** - transactions are not conducted on the platform, information about them comes from users
4. **Security** - special fees and reservations are stored in a separate database on a physically separate server

### API and Client Applications

#### Working through API:
- **Main interface** - strictly through REST API
- **Mobile applications** - Kotlin for Android and iOS applications
- **External IP** - functions for working with external IP addresses
- **Collateral tokens** - issued only through mobile applications

#### Token System:
- **Collateral tokens** - main user identifier, issued only through mobile applications
  - Stored only in the core in a separate database until the end of the transaction
- **User tokens** - Bearer tokens in hash format for API authentication
  - Session tokens (permanent or for a year)
  - Used for API access
- **Test groups** - in sandbox mode, the `test_group` field is used for user grouping
  - Required field in requests to tables: exchange_lots, buy_triggers, transactions

### Key Features

1. **XRP collateral tokens** - cheap and fast exchange without storing data in the database (with sandbox for testing)
2. **Data encryption** - information about wallets and fee rules is stored in encrypted form
3. **Physical protection** - servers are located in secure places
4. **Fee system** - deposit/withdrawal of funds charges a fee according to the rules from the token

### Architecture

- **Frontend**: Kotlin for Android and iOS applications
- **Backend**: Laravel PHP 8.4
- **Database**: MySQL (see [schema.sql](../schema.sql))
- **API**: REST API with Bearer tokens and Swagger documentation
- **External service**: Distributed core on separate equipment for secure operations
  - Domains: `sandbox.core*.xn--80apfreedjfbd.xn--p1ai` / `prod.core*.xn--80apfreedjfbd.xn--p1ai`
  - Scaling: core1, core2, core3... for fault tolerance

### Database Structure

#### Reference Data
- **Countries** (countries) - ISO 3166-1 alpha-3 codes
- **Fiat currencies** (fiat_currencies) - ISO 4217 codes
- **Cryptocurrencies** (crypto_currencies) — major + stablecoins; схема синхронизирована с Core (см. [CRYPTO_CURRENCIES_MIGRATION.md](CRYPTO_CURRENCIES_MIGRATION.md))
- **Banks** (banks) - linked to countries and currencies

#### Main Tables
- **Exchange board** (exchange_lots) - currency sale lots
- **Buy triggers** (buy_triggers) - automatic purchases
- **Transactions** (transactions) - external transactions from users

#### Features
- Collateral tokens contain cryptocurrency ID and wallet address
- Test mode support (is_test, test_group)
- Indexes for fast search by tokens and addresses

#### test_group fields:
- **Purpose**: user grouping in sandbox mode
- **Usage**: required field in requests to tables with test_group
- **Tables**: exchange_lots, buy_triggers, transactions
- **Logic**: in test mode, all requests should contain test_group for data isolation

## Documentation

- [Database schema](../schema.sql)
- [External service API documentation](core_swagger.yaml) - for checking external transfers through nodes
- [Core system code](../XRPChangeCore) - separate service for critical operations

## Tasks

### Current Tasks
- [x] Create basic project structure
- [x] Set up reference data (countries, currencies, banks)
- [x] Implement exchange board (exchange_lots)
- [x] Implement buy triggers (buy_triggers)
- [x] Create external transaction processing system
- [x] Implement test mode (sandbox)
- [x] Bearer token system for API authentication
- [x] Test group management in sandbox
- [x] Create complete API documentation (Swagger)
- [x] Update README.md for project
- [ ] Core system integration (XRPChangeCore)
- [ ] Push notification system for collateral tokens
- [ ] Distributed core server configuration

### Planned Tasks
- [ ] XRP Ledger integration (with sandbox for testing)
- [ ] Moneyra integration (minimal implementation in core) // unclear what to do here yet
- [ ] Exchange board security system (DDoS protection, token notifications)

## Technical Details

### Collateral Tokens
- Use Ripple (XRP) for fast and cheap transactions (with sandbox for testing)
- Planned Moneyra support (minimal implementation in core)
- Contain encrypted information about wallets and fee rules
- Provide security without storing data in the database

### Exchange Board Security
- DDoS attack protection on API
- Push notifications to phones about collateral tokens (strictly through push, not through API)
- Token information should not be available through regular API requests

### System Core
- Separate service in `../XRPChangeCore` folder
- Processing all critical operations (fees, reservations)
- Physically separate equipment

#### Distributed Core Architecture:
- **Domain separation**: `sandbox.core*.xn--80apfreedjfbd.xn--p1ai` and `prod.core*.xn--80apfreedjfbd.xn--p1ai`
- **Scaling**: core1, core2, core3... for fault tolerance
- **Unified DB**: all core servers work with one database for transaction tracking
- **Configuration**: server management through config (currently one box, more planned)
- **Fault tolerance**: servers try not to go down simultaneously

#### Core Main Functions:
- **Collateral token creation** - generation of encrypted tokens with wallet data
- **Token storage** - collateral tokens are stored only in the core in a separate database until the end of the transaction
- **Deposit/withdrawal** - XRP operations through XRP Ledger
- **Buyer compensation** - automatic payments during deals
- **Fund reservation** - fund blocking for deals
- **Fee calculation** - considering client type (individual/legal entity)
- **Data encryption** - AES-256-CBC for all critical data

#### XRP Integration:
- Uses `Hardcastle\XRPL_PHP` library
- Sandbox and production support
- Automatic account replenishment
- Address and seed validation

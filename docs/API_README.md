# XBoard API - Brief Documentation

## Overview

XBoard API provides REST API for P2P cryptocurrency exchange with collateral guarantee. The API uses Escrow token authentication (X-Escrow-Token header) and supports test mode.

## Base URL

- **Development**: `http://localhost:8000/api/v1`
- **Production**: `https://api.xboard.com/v1`

## Authentication

### Escrow Token Authentication

All protected endpoints require `X-Escrow-Token` header with the public part (publicPart) of escrow token:

```http
X-Escrow-Token: {"version":"1.0","wallet_address":"r123...","currency":"XRP","node_domain":"xdexboard.com"}
```

### Validate token
```http
POST /api/v1/auth/validate-token
Content-Type: application/json

{
  "escrow_token": "{\"version\":\"1.0\",\"wallet_address\":\"r123...\",\"currency\":\"XRP\"}",
  "device_id": "optional-device-id"
}
```

## Reference Data (public)

### Countries
```http
GET /countries
GET /countries/{country_id}
GET /countries/{country_id}/banks
```

### Currencies
```http
GET /currencies/fiat
GET /currencies/crypto
GET /currencies/all
```

### Banks
```http
GET /banks
GET /banks/{bank_id}
GET /banks/country/{country_code}
```

## Exchange Board (protected)

### Get lots
```http
GET /exchange-lots
X-Escrow-Token: {"version":"1.0","wallet_address":"r123...","currency":"XRP"}
```

**Parameters:**
- `is_test` - test mode filter
- `test_group` - test mode group
- `sell_currency_type` - sell currency type (fiat/crypto)
- `sell_currency_id` - sell currency ID
- `buy_currency_type` - buy currency type (fiat/crypto)
- `buy_currency_id` - buy currency ID

### Create lot
```http
POST /exchange-lots
X-Escrow-Token: {"version":"1.0","wallet_address":"r123...","currency":"XRP"}
Content-Type: application/json

{
  "sell_currency_type": "fiat",
  "sell_currency_id": 1,
  "sell_amount": 1000.00,
  "buy_currency_type": "crypto",
  "buy_currency_id": 1,
  "buy_amount": 0.5,
  "country_id": 1,
  "bank_id": 1,
  "bank_account": "1234567890",
  "payment_purpose": "Service payment",
  "contract_url": "https://example.com/contract.pdf",
  "is_for_individuals": true,
  "is_for_legal_entities": false,
  "is_test": false,
  "test_group": "group1"
}
```

## Buy Triggers (protected)

### Get triggers
```http
GET /buy-triggers
X-Escrow-Token: {"version":"1.0","wallet_address":"r123...","currency":"XRP"}
```

### Create trigger
```http
POST /buy-triggers
X-Escrow-Token: {"version":"1.0","wallet_address":"r123...","currency":"XRP"}
Content-Type: application/json

{
  "sell_currency_type": "crypto",
  "sell_currency_id": 1,
  "sell_amount": 0.1,
  "buy_currency_type": "fiat",
  "buy_currency_id": 1,
  "buy_amount": 200.00,
  "country_id": 1,
  "bank_id": 1,
  "bank_account": "1234567890",
  "is_for_individuals": true,
  "is_for_legal_entities": false,
  "is_test": false,
  "test_group": "group1"
}
```

## Transactions (protected)

### Get transactions
```http
GET /transactions
X-Escrow-Token: {"version":"1.0","wallet_address":"r123...","currency":"XRP"}
```

### Transaction statistics
```http
GET /transactions/stats
X-Escrow-Token: {"version":"1.0","wallet_address":"r123...","currency":"XRP"}
```

### Create transaction
```http
POST /transactions
X-Escrow-Token: {"version":"1.0","wallet_address":"r123...","currency":"XRP"}
Content-Type: application/json

{
  "exchange_lot_id": 1,
  "buy_trigger_id": null,
  "transaction_hash": "0x1234567890abcdef",
  "amount": 1000.00,
  "status": "pending",
  "is_test": false,
  "test_group": "group1"
}
```

## Test Mode

In test mode, all requests should contain the `test_group` parameter for data isolation:

```json
{
  "is_test": true,
  "test_group": "my_test_group"
}
```

## Response Codes

- `200` - Successful request
- `201` - Resource created
- `400` - Validation error
- `401` - Unauthorized access
- `404` - Resource not found
- `422` - Data validation error
- `500` - Internal server error

## Usage Examples

### Get all currencies
```bash
curl -X GET "http://localhost:8000/api/v1/currencies/all"
```

### Create lot (with authentication)
```bash
curl -X POST "http://localhost:8000/api/v1/exchange-lots" \
  -H "X-Escrow-Token: {"version":"1.0","wallet_address":"r123...","currency":"XRP"}" \
  -H "Content-Type: application/json" \
  -d '{
    "sell_currency_type": "fiat",
    "sell_currency_id": 1,
    "sell_amount": 1000.00,
    "buy_currency_type": "crypto",
    "buy_currency_id": 1,
    "buy_amount": 0.5,
    "country_id": 1,
    "bank_id": 1,
    "bank_account": "1234567890",
    "payment_purpose": "Service payment",
    "is_for_individuals": true,
    "is_for_legal_entities": false
  }'
```

## Payment Verification (Email) - Testing Mode

Email verification endpoints for testing DKIM validation and email processing. See [EMAIL_VERIFICATION_METHODS.md](EMAIL_VERIFICATION_METHODS.md) for complete documentation.

**Email List/Raw Email (OAuth token required):**
- `GET /api/v1/payments/emails` - Get email list from OAuth-connected account
- `GET /api/v1/payments/emails/{messageId}` - Get raw email by message ID

**Email Verification/Forwarding (No authentication required - testing mode):**
- `POST /api/v1/payments/verify-email` - Verify email and DKIM (multiple formats)
- `POST /api/v1/payments/verify-email-content` - Verify email content (DKIM, sender). Optional `bank_id` binds verification to a specific bank from `X-XBoard-Deal-Tag`; sender must match this bank whitelist entry. If DKIM/sender pass but no PDF links are extracted, email is treated as invalid.
- `POST /api/v1/payments/forward-email` - Forward email to verification server
- `GET /api/v1/payments/email-attachments/{date}/{token}.pdf` - Open a PDF at permanent path (date = UTC YYYY-MM-DD). Used for links stored in `receipt_links_json` after promotion from tmp.
- `GET /api/v1/payments/email-attachments/{date}/tmp/{token}.pdf` - Open a PDF in the tmp area. All extracted PDFs are saved to tmp first; when receipt links are persisted (e.g. in a transaction), those files are moved to the permanent path and the stored links are updated to the `.../date/{token}.pdf` form. Tmp is pruned daily (see SCHEDULED_PRUNING.md).

**Important:** 
- Email list/raw endpoints use OAuth access tokens (Yandex/Gmail/Outlook)
- Verification/forwarding endpoints are in testing mode - no authentication required
- All endpoints focus on testing email verification and DKIM validation functionality
- No transaction-related data required - pure email verification and DKIM testing

## Полная документация

Для полной документации с примерами и схемами данных см. [api_swagger.yaml](api_swagger.yaml) 
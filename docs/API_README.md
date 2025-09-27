# XBoard API - Brief Documentation

## Overview

XBoard API provides REST API for P2P cryptocurrency exchange with collateral guarantee. The API uses Bearer tokens for authentication and supports test mode.

## Base URL

- **Development**: `http://localhost:8000/api/v1`
- **Production**: `https://api.xboard.com/v1`

## Authentication

### Get Bearer token
```http
POST /auth/bearer-token
Content-Type: application/json

{
  "escrow_token": "your_escrow_token_here",
  "test_group": "optional_test_group"
}
```

### Validate token
```http
GET /auth/validate
Authorization: Bearer your_bearer_token_here
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
Authorization: Bearer your_token
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
Authorization: Bearer your_token
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
Authorization: Bearer your_token
```

### Create trigger
```http
POST /buy-triggers
Authorization: Bearer your_token
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
Authorization: Bearer your_token
```

### Transaction statistics
```http
GET /transactions/stats
Authorization: Bearer your_token
```

### Create transaction
```http
POST /transactions
Authorization: Bearer your_token
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
  -H "Authorization: Bearer your_token" \
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

## Полная документация

Для полной документации с примерами и схемами данных см. [api_swagger.yaml](api_swagger.yaml) 
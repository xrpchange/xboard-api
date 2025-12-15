# Escrow Token Authentication System

## Concept

Simple authentication system based on the open part (publicPart) of escrow tokens. **No Bearer tokens and expiration time** - this is a relic from the pre-HTTPS era.

## Core Paradigm: Account-Based, Not User-Based

**Important:** The system tracks movements and operations by **escrow account** (escrow token), not by user or organization. This is a fundamental architectural decision:

- **Movements are tied to escrow accounts**: All transactions, balances, and operations are associated with the escrow token (account), not with a person or organization
- **KYC is optional and separate**: If needed for analytics or compliance, KYC (Know Your Customer) can be implemented later to link an escrow account to a user/organization
- **Privacy-first approach**: Users can operate without revealing their identity, as long as they have a valid escrow token
- **Analytics flexibility**: When KYC is added, it becomes a separate layer that links accounts to users for reporting and compliance purposes

This paradigm allows:
- **Privacy**: Users don't need to register or provide personal information to use the system
- **Flexibility**: KYC can be added incrementally where needed (e.g., for regulatory compliance in specific jurisdictions)
- **Scalability**: Account-based tracking is simpler and more efficient than user-based tracking
- **Security**: Compromised KYC data doesn't affect account security (escrow tokens remain independent)

## Key Principles

1. **Authentication via publicPart**: Client sends the open part of escrow token (JSON string) in every request
2. **Caching for performance**: `escrow_token_cache` table stores validation results to avoid calling Core API on every request
3. **Validation via Core API**: When needed (cache is stale or missing), token is validated through Core API
4. **No expiration time**: Token is valid as long as escrow token is valid in Core API

## Architecture

### `escrow_token_cache` Table

Simple cache for fast token validation without Core API calls.

**Structure:**
- `token_hash` (unique) - Part of escrow token, comes from Core API in publicPart
- `public_part` (text) - Full publicPart JSON string (contains wallet_address, currency, node_domain, environment)
- `currency` - Currency (XRP, BTC, XMR, etc.) - extracted from publicPart for indexing
- `node_domain` - Node domain where token was created - extracted from publicPart for indexing
- `environment` - Environment (sandbox, production) - extracted from publicPart for indexing
- `device_id` - Device ID (for statistics)
- `ip_address` - IP address (for statistics)
- `last_validated_at` - Last time token was validated via Core API
- `last_used_at` - Last time token was used for authentication

**Note:** `wallet_address` is NOT stored separately - it's already in `public_part` JSON. This reduces data duplication and improves security.

**Caching logic:**
- If token exists in cache (`last_validated_at` is set) - token is considered valid, no validation needed
- If token is NOT in cache (`last_validated_at` is null) - **MUST validate via Core API** and add to cache
- After successful validation, `last_validated_at` is updated
- **Important:** Once token is validated and added to cache, it remains valid. There are no "stale" tokens - if token is in table, it's valid.

### API Endpoints

#### `POST /api/v1/auth/validate-token`

Validate escrow token (publicPart).

**Request:**
```json
{
  "escrow_token": "{\"version\":\"1.0\",\"wallet_address\":\"r123...\",\"currency\":\"XRP\",\"node_domain\":\"xdexboard.com\"}",
  "device_id": "optional-device-id"
}
```

**Response (success):**
```json
{
  "success": true,
  "data": {
    "valid": true,
    "wallet_address": "r123...",
    "crypto_currency": "XRP",
    "node_domain": "xdexboard.com",
    "environment": "sandbox"
  }
}
```

**Response (error):**
```json
{
  "success": false,
  "message": "Invalid escrow token: ..."
}
```

### `ValidateEscrowToken` Middleware

Validates token in every request to protected endpoints.

**Usage:**
```php
Route::middleware(['escrow.token'])->group(function () {
    // Protected routes
});
```

**Where to find token:**
1. Header: `X-Escrow-Token` (preferred)
2. Request body: `escrow_token`
3. Query parameter: `escrow_token`

**After validation:**
- Token data available in `$request->escrow_token_data`
- Cache entry available in `$request->escrow_token_cache`

## Validation Process

1. **Client sends request** with `publicPart` in `X-Escrow-Token` header
2. **Middleware receives token** and calls `EscrowTokenValidationService::validateToken()`
3. **Service checks cache:**
   - If token exists in cache (`last_validated_at` is set) - returns as valid, no validation needed
   - If token is NOT in cache (`last_validated_at` is null) - validates via Core API
4. **Validation via Core API (only for tokens not in cache):**
   - POST request to `{core_api_url}/api/escrow-tokens/validate`
   - Body: `{"token_hash": tokenHash, "crypto_type": cryptoType}`
   - Core API checks if token exists and is valid
   - **Important:** Only tokens that are NOT in cache table are validated through Core API
5. **Cache update:**
   - After successful validation, `last_validated_at` is updated
   - `last_used_at` is updated on every use
   - **Note:** Once token is validated and added to cache, it remains valid forever (no re-validation needed)

## Benefits of Simplified System

### For Development

1. **Less code**: Removed Bearer token management, refresh logic, expiration checks
2. **Easier debugging**: Token is just a JSON string, easy to inspect
3. **Fewer states**: No "token expired", "needs refresh", "token not found" states
4. **Direct connection**: Client uses the same token as for other operations (Core API)

### For Migrations

1. **No token versioning**: Token is always in one format (publicPart JSON)
2. **Easier updates**: Changes in token format are visible immediately, no Bearer token migration needed
3. **Backward compatibility**: Old tokens continue to work if publicPart format doesn't change
4. **Fewer dependencies**: No need to synchronize token expiration between client and server

### For Security

1. **HTTPS protection**: Token is transmitted over HTTPS, protected from interception
2. **No long-lived tokens**: Every request is validated via Core API (with caching)
3. **No refresh attacks**: No refresh tokens that can be stolen and used indefinitely
4. **Transparency**: Token is the open part of escrow token that client already has

## Migration from Bearer Tokens

### On Server:
1. Create `escrow_token_cache` table (migration already created)
2. Update routes: `/auth/validate-token` instead of `/auth/bearer-token`
3. Use middleware `escrow.token` instead of Bearer token middleware

### On Client:
1. Remove all Bearer token management code
2. Send `publicPart` in `X-Escrow-Token` header in every request
3. Remove refresh token logic, token storage, expiration checks

## Client Usage Example

```kotlin
// Instead of Bearer token
val publicPart = escrowToken.publicPart

// In every API request
httpClient.post(url) {
    header("X-Escrow-Token", publicPart)
    header("X-Device-ID", deviceId) // optional
    // ... other headers
}
```

## Configuration

In `config/app.php` or `.env`:
```php
CORE_API_URL=https://sandbox.api.core.xdexboard.com
```

## Logging

All operations are logged:
- Validation requests
- Cache usage vs Core API
- Validation errors
- Token usage statistics

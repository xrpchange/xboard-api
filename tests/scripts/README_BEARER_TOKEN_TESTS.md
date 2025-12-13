# Bearer Token System Tests

## Overview

This directory contains test scripts for the Bearer Token Management System.

## Test Scripts

### `test_bearer_token_system.php`

Comprehensive test script that validates all Bearer token functionality:

1. **Get Bearer Token** - Obtains Bearer token from escrow token
2. **Validate Token** - Validates the obtained Bearer token
3. **Refresh Token** - Refreshes the Bearer token using refresh endpoint
4. **Validate Refreshed Token** - Validates the refreshed token
5. **Authenticated Request** - Uses Bearer token for authenticated API call
6. **Invalid Token Handling** - Tests rejection of invalid tokens
7. **Invalid Refresh** - Tests rejection of invalid token refresh
8. **Country Filter** - Tests nodes endpoint with country filter parameter

## Usage

```bash
cd tests/scripts
php test_bearer_token_system.php '<escrow_token_json>'
```

### Example

```bash
php test_bearer_token_system.php '{"version":"1.0","wallet_address":"tb1q7j5frmvm0hq527rtfgf8y83qvm9ms7m28ava0l","currency":"BTC","environment":"sandbox","node_domain":"xdexboard.com","token_hash":"01cd36b0299a236915e8a0500c53cabe0520a00b5ad784952231f36e6aca050a"}'
```

## Expected Results

All tests should pass:
- ✓ Get Bearer token from escrow token
- ✓ Validate Bearer token
- ✓ Refresh Bearer token
- ✓ Validate refreshed Bearer token
- ✓ Authenticated request successful
- ✓ Invalid token correctly rejected
- ✓ Invalid token refresh correctly rejected
- ✓ Country filter request successful

## Test Scenarios

### Scenario 1: Normal Flow
1. Get Bearer token from escrow token → Should succeed
2. Validate token → Should return valid
3. Refresh token → Should return new token
4. Use token for API call → Should succeed

### Scenario 2: Error Handling
1. Validate invalid token → Should return 401
2. Refresh invalid token → Should return 401

### Scenario 3: Country Filter
1. Get nodes with country filter → Should return filtered nodes

## Notes

- Escrow tokens are eternal (never expire)
- Bearer tokens expire after 1 month
- Refresh endpoint should be used when Bearer token expires
- If refresh fails, new Bearer token can be obtained using escrow token


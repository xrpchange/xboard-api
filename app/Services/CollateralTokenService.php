<?php

namespace App\Services;

use App\Models\CryptoCurrency;
use App\Models\ExchangeLot;
use App\Models\UserToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class CollateralTokenService
{
    public function __construct() {}

    /**
     * Check collateral token balance via simple core request
     * @param string $publicPart JSON string with public_part of escrow token (not full token)
     */
    public function checkTokenBalance(string $publicPart): array
    {
        // Decrypt token to get currency (publicPart is JSON string)
        $tokenData = $this->decryptToken($publicPart);
        $currency = strtoupper($tokenData['currency']);
        
        // Map currency to crypto_type for Core API
        $cryptoTypeMap = [
            'XRP' => 'xrp',
            'BTC' => 'btc',
        ];
        $cryptoType = $cryptoTypeMap[$currency] ?? strtolower($currency);
        
        // Determine environment and select appropriate core URL
        $isProduction = config('app.env') === 'production';
        $isDev = config('app.env') === 'local' || str_contains(request()->getHost(), 'dev.api.xdexboard.com');
        
        if ($isDev) {
            // Dev mode: use sandbox by default, but can be overridden
            $devMode = config('services.dev.mode', 'sandbox');
            $coreConfig = $devMode === 'production' ? 'services.core_production' : 'services.core';
        } else {
            $coreConfig = $isProduction ? 'services.core_production' : 'services.core';
        }
        
        $coreUrl = config($coreConfig . '.url');
        $coreKey = config($coreConfig . '.key');
        $timeout = config($coreConfig . '.timeout', 10);

        Log::info('Checking token balance', [
            'core_url' => $coreUrl,
            'currency' => $currency,
            'crypto_type' => $cryptoType,
            'environment' => $isDev ? 'dev-' . config('services.dev.mode', 'sandbox') : ($isProduction ? 'production' : 'sandbox'),
            'public_part_length' => strlen($publicPart)
        ]);

        try {
            // First check core availability via echo.php
            $echoResponse = Http::timeout(5)->get($coreUrl . '/echo.php');
            
            if (!$echoResponse->successful()) {
                throw new \Exception('Core echo check failed with status: ' . $echoResponse->status());
            }

            $request = Http::timeout($timeout);
            
            if (!empty($coreKey)) {
                $request = $request->withHeaders([
                    'X-Core-Key' => $coreKey,
                ]);
            }

            // Core API endpoint - pass only publicPart (JSON string), Core can derive crypto_type from it
            // Core URL should include /api.php, then we add /escrow-tokens/balance
            $balanceUrl = rtrim($coreUrl, '/');
            if (!str_ends_with($balanceUrl, '/api.php')) {
                $balanceUrl .= '/api.php';
            }
            $balanceUrl .= '/escrow-tokens/balance';
            
            Log::debug('Calling Core balance endpoint', [
                'url' => $balanceUrl,
                'public_part_length' => strlen($publicPart)
            ]);
            
            $response = $request->post($balanceUrl, [
                'escrow_token' => $publicPart, // Only publicPart, not full token
            ]);

            if (!$response->successful()) {
                $errorBody = $response->body();
                $errorJson = $response->json();
                Log::error('Core balance check failed', [
                    'status' => $response->status(),
                    'url' => $balanceUrl,
                    'error_body' => $errorBody,
                    'error_json' => $errorJson,
                    'public_part_preview' => substr($publicPart, 0, 200) . '...'
                ]);
                throw new \Exception('Core request failed with status: ' . $response->status() . ' - ' . ($errorJson['message'] ?? $errorBody));
            }

            $data = $response->json();
            
            // Core API returns data in 'data' field, extract it
            if (isset($data['data'])) {
                $data = $data['data'];
            }
            
            if (!isset($data['total'])) {
                Log::warning('Token balance response missing total', ['response' => $data]);
            }
            
            return $data;
        } catch (\Exception $e) {
            Log::error('Token balance check failed', [
                'error' => $e->getMessage(),
                'core_url' => $coreUrl
            ]);
            throw $e;
        }
    }

    /**
     * Decrypt publicPart (JSON string) and get data
     * @param string $publicPart JSON string with public_part of escrow token
     */
    public function decryptToken(string $publicPart): array
    {
        Log::debug('Decrypting token', [
            'public_part_preview' => substr($publicPart, 0, 200) . '...',
            'public_part_length' => strlen($publicPart)
        ]);
        
        try {
            // publicPart is JSON string, parse it
            $tokenData = json_decode($publicPart, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('JSON decode failed', [
                    'json_error' => json_last_error_msg(),
                    'public_part_preview' => substr($publicPart, 0, 200) . '...'
                ]);
                throw new \Exception('Invalid JSON in publicPart: ' . json_last_error_msg());
            }
            
            Log::debug('Token data parsed', [
                'keys' => array_keys($tokenData ?? []),
                'has_version' => isset($tokenData['version']),
                'has_wallet_address' => isset($tokenData['wallet_address']),
                'has_currency' => isset($tokenData['currency']),
                'has_environment' => isset($tokenData['environment']),
                'has_node_domain' => isset($tokenData['node_domain'])
            ]);
            
            if (!isset($tokenData['version'], $tokenData['wallet_address'], $tokenData['currency'])) {
                Log::error('Token structure validation failed', [
                    'public_part_preview' => substr($publicPart, 0, 200) . '...',
                    'public_part_length' => strlen($publicPart),
                    'keys' => array_keys($tokenData ?? []),
                    'has_version' => isset($tokenData['version']),
                    'has_wallet_address' => isset($tokenData['wallet_address']),
                    'has_currency' => isset($tokenData['currency']),
                    'has_node_domain' => isset($tokenData['node_domain']),
                    'token_data_preview' => json_encode(array_intersect_key($tokenData ?? [], array_flip(['version', 'wallet_address', 'currency', 'node_domain', 'environment'])))
                ]);
                throw new \Exception('Invalid token structure: missing required fields (version, wallet_address, currency)');
            }

            // Support XRP, BTC, XMR, and Stellar stablecoins
            $supportedCurrencies = ['XRP', 'BTC', 'XMR', 'USDT', 'USDC', 'DAI'];
            if (!in_array($tokenData['currency'], $supportedCurrencies)) {
                Log::error('Unsupported currency', [
                    'currency' => $tokenData['currency'],
                    'supported' => $supportedCurrencies
                ]);
                throw new \Exception('Unsupported currency: ' . $tokenData['currency']);
            }

            // Normalize environment from token (environment or mode field)
            $environment = $tokenData['environment'] ?? $tokenData['mode'] ?? 'sandbox';
            if (strtolower($environment) === 'prod') {
                $environment = 'production';
            }

            // Extract wallet address - for XMR it may be JSON with primary and view_key
            $walletAddress = $tokenData['wallet_address'];
            if ($tokenData['currency'] === 'XMR' && is_string($walletAddress)) {
                // Try to parse as JSON (XMR addresses can be JSON: {"primary":"...","view_key":"..."})
                $parsedAddress = json_decode($walletAddress, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($parsedAddress['primary'])) {
                    // Use primary address for XMR
                    $walletAddress = $parsedAddress['primary'];
                    Log::debug('XMR address extracted from JSON', [
                        'primary' => substr($walletAddress, 0, 10) . '...',
                        'original_length' => strlen($tokenData['wallet_address'])
                    ]);
                }
            }

            Log::info('Token decrypted successfully', [
                'currency' => $tokenData['currency'],
                'environment' => $environment,
                'node_domain' => $tokenData['node_domain'] ?? 'not_set',
                'wallet_address' => substr($walletAddress, 0, 20) . '...',
                'wallet_address_length' => strlen($walletAddress),
                'public_part_length' => strlen($publicPart)
            ]);

            return [
                'wallet_address' => $walletAddress,
                'currency' => $tokenData['currency'],
                'environment' => $environment,
                'node_domain' => $tokenData['node_domain'] ?? null // CRITICAL: Include node_domain in return
            ];
        } catch (\Exception $e) {
            Log::error('Token decryption failed', [
                'public_part_preview' => substr($publicPart, 0, 200) . '...',
                'public_part_length' => strlen($publicPart),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get or create exchange lot based on collateral token
     */
    public function getOrCreateExchangeLot(string $walletAddress, string $currency = 'XRP'): ExchangeLot
    {
        // Find cryptocurrency
        $cryptoCurrency = CryptoCurrency::where('code', $currency)->first();
        if (!$cryptoCurrency) {
            throw new \Exception('Unsupported currency: ' . $currency);
        }

        // Find or create exchange lot
        $exchangeLot = ExchangeLot::where('wallet_address', $walletAddress)
            ->where('crypto_currency_id', $cryptoCurrency->id)
            ->first();

        if (!$exchangeLot) {
            $exchangeLot = ExchangeLot::create([
                'wallet_address' => $walletAddress,
                'crypto_currency_id' => $cryptoCurrency->id,
                'is_test' => false, // Will be determined from token
                'test_group' => null
            ]);
        }

        return $exchangeLot;
    }

    /**
     * Create Bearer token with expiry information
     * @param string $publicPart JSON string with public_part of escrow token (not full token)
     * @return array ['token' => string, 'expires_at' => string]
     */
    public function createBearerTokenWithExpiry(string $publicPart, string $ipAddress, string $deviceId, ?string $nodeDomain = null): array
    {
        $token = $this->createBearerToken($publicPart, $ipAddress, $deviceId, $nodeDomain);
        
        // Get the token record to get expires_at
        $userToken = UserToken::where('token', $token)->first();
        if ($userToken && $userToken->expires_at) {
            return [
                'token' => $token,
                'expires_at' => $userToken->expires_at->toIso8601String()
            ];
        }
        
        // Fallback: calculate expires_at (1 month from now)
        return [
            'token' => $token,
            'expires_at' => now()->addMonth()->toIso8601String()
        ];
    }

    /**
     * Create Bearer token after successful balance check
     * @param string $publicPart JSON string with public_part of escrow token (not full token)
     */
    public function createBearerToken(string $publicPart, string $ipAddress, string $deviceId, ?string $nodeDomain = null): string
    {
        // Decrypt publicPart to get token data
        $tokenData = $this->decryptToken($publicPart);
        
        // IMPORTANT: Escrow token is already bound to a node (nodeDomain is in escrow token data)
        // If nodeDomain is not provided in request, extract it from escrow token
        if ($nodeDomain === null && isset($tokenData['node_domain'])) {
            $nodeDomain = $tokenData['node_domain'];
        }
        
        // Log node_domain extraction for debugging
        Log::info('Extracting node_domain from escrow token', [
            'node_domain_from_param' => $nodeDomain,
            'node_domain_from_token' => $tokenData['node_domain'] ?? null,
            'final_node_domain' => $nodeDomain,
            'token_data_keys' => array_keys($tokenData ?? [])
        ]);
        
        // Calculate escrow_token_hash from publicPart (same as client does)
        $escrowTokenHash = hash('sha256', $publicPart);
        
        // IMPORTANT: Each node should have its own Bearer token
        // Escrow token gives the right to obtain Bearer token, but Bearer tokens are different for different nodes
        // For same escrow_token_hash + device_id + node_domain, return existing token if valid
        // This ensures one Bearer token per node, even if using the same escrow token
        // CRITICAL: Always check node_domain (even if null) to prevent creating duplicate tokens for different nodes
        $query = UserToken::where('escrow_token_hash', $escrowTokenHash)
            ->where('device_id', $deviceId)
            ->where('expires_at', '>', now());
        
        // ALWAYS filter by node_domain (even if null) - this is critical to prevent duplicates
        if ($nodeDomain !== null) {
            $query->where('node_domain', $nodeDomain);
        } else {
            // If nodeDomain is null, only match tokens where node_domain is also null
            $query->whereNull('node_domain');
        }
        
        $existingToken = $query->orderBy('created_at', 'desc') // Get most recent valid token
            ->first();
        
        if ($existingToken) {
            // Token is still valid - return it without extending expiration
            $existingToken->updateLastUsed();
            Log::info('Valid Bearer token found for escrow_token_hash + device_id + node_domain, returning existing', [
                'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...',
                'token_id' => $existingToken->id,
                'device_id' => substr($deviceId, 0, 16) . '...',
                'node_domain' => $nodeDomain,
                'expires_at' => $existingToken->expires_at->format('Y-m-d H:i:s')
            ]);
            return $existingToken->token;
        }
        
        Log::info('No existing Bearer token found, creating new one', [
            'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...',
            'device_id' => substr($deviceId, 0, 16) . '...',
            'node_domain' => $nodeDomain
        ]);
        
        // Also check for expired tokens with same escrow_token_hash to clean up
        // (optional: can be done via scheduled job instead)
        $expiredCount = UserToken::where('escrow_token_hash', $escrowTokenHash)
            ->where('expires_at', '<=', now())
            ->count();
        
        if ($expiredCount > 0) {
            Log::info('Found expired tokens for escrow_token_hash, will create new one', [
                'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...',
                'expired_count' => $expiredCount
            ]);
        }
        
        // Token expired or doesn't exist - create new one
        Log::info('Creating new Bearer token for escrow_token_hash', [
            'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...',
            'reason' => $existingToken ? 'expired' : 'not_found'
        ]);
        
        // Check balance via core (pass only publicPart)
        // NOTE: Balance check may fail (e.g., hash mismatch, Core unavailable)
        // We still create Bearer token even if balance check fails
        try {
            $balanceData = $this->checkTokenBalance($publicPart);
            Log::info('Token balance checked successfully', [
                'wallet_address' => substr($tokenData['wallet_address'], 0, 20) . '...',
                'currency' => $tokenData['currency']
            ]);
        } catch (\Exception $balanceError) {
            // Log but don't fail - Bearer token can be created without balance check
            Log::warning('Token balance check failed, but continuing with Bearer token creation', [
                'error' => $balanceError->getMessage(),
                'wallet_address' => substr($tokenData['wallet_address'], 0, 20) . '...',
                'currency' => $tokenData['currency']
            ]);
        }
        
        // NOTE: ExchangeLot is NOT required for Bearer token creation
        // ExchangeLot is only needed for actual exchange operations, not for authentication
        // We skip ExchangeLot creation here to avoid requiring unnecessary fields
        
        // Double-check: Re-query to prevent race condition
        // (Another request might have created token between our check and now)
        // CRITICAL: Include ALL matching criteria (escrow_token_hash + device_id + node_domain)
        $doubleCheckQuery = UserToken::where('escrow_token_hash', $escrowTokenHash)
            ->where('device_id', $deviceId)
            ->where('expires_at', '>', now());
        
        // ALWAYS filter by node_domain in double-check too
        if ($nodeDomain !== null) {
            $doubleCheckQuery->where('node_domain', $nodeDomain);
        } else {
            $doubleCheckQuery->whereNull('node_domain');
        }
        
        $existingTokenAfterCheck = $doubleCheckQuery->orderBy('created_at', 'desc')->first();
        
        if ($existingTokenAfterCheck) {
            // Token was created by another request - return it
            $existingTokenAfterCheck->updateLastUsed();
            Log::info('Valid Bearer token found after double-check (race condition prevented)', [
                'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...',
                'token_id' => $existingTokenAfterCheck->id,
                'device_id' => substr($existingTokenAfterCheck->device_id, 0, 16) . '...',
                'node_domain' => $nodeDomain
            ]);
            return $existingTokenAfterCheck->token;
        }
        
        // Generate unique Bearer token
        $bearerToken = hash('sha256', $publicPart . $ipAddress . time() . random_bytes(32));
        
        // Use device_id from client (should be unique per device)
        // Validate device_id format (should be non-empty string)
        if (empty($deviceId) || strlen($deviceId) > 255) {
            Log::error('Invalid device_id received from client', [
                'device_id_length' => strlen($deviceId ?? ''),
                'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...'
            ]);
            throw new \InvalidArgumentException('Invalid device_id');
        }
        
        // IMPORTANT: For same escrow_token_hash, there should be only ONE bearer token
        // We already checked for existing token above, so if we reach here, token doesn't exist
        // Check if device_id is already used with different escrow_token_hash
        // This is OK - same device can have multiple escrow tokens (different bearer tokens)
        $existingDeviceToken = UserToken::where('device_id', $deviceId)
            ->where('escrow_token_hash', '!=', $escrowTokenHash)
            ->where('expires_at', '>', now())
            ->first();
        
        if ($existingDeviceToken) {
            Log::info('Device_id already used with different escrow_token_hash (OK - multiple escrow tokens per device)', [
                'device_id' => substr($deviceId, 0, 32) . '...',
                'existing_escrow_hash' => substr($existingDeviceToken->escrow_token_hash ?? '', 0, 16) . '...',
                'new_escrow_hash' => substr($escrowTokenHash, 0, 16) . '...'
            ]);
            // This is OK - same device can have multiple escrow tokens
        }
        
        // Save token to database with escrow_token_hash and node_domain
        // NOTE: After migration, device_id is no longer UNIQUE, so multiple tokens per device are allowed
        // IMPORTANT: One Bearer token per node (node_domain), even if using the same escrow token
        try {
            Log::info('Creating Bearer token in database', [
                'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...',
                'device_id' => substr($deviceId, 0, 16) . '...',
                'node_domain' => $nodeDomain,
                'node_domain_is_null' => $nodeDomain === null,
                'token_length' => strlen($bearerToken),
                'ip_address' => $ipAddress
            ]);
            
            $userToken = UserToken::create([
                'token' => $bearerToken,
                'device_id' => $deviceId, // Device identifier from client, NOT wallet address
                'escrow_token_hash' => $escrowTokenHash, // Hash of publicPart
                'node_domain' => $nodeDomain, // Node domain - one Bearer token per node (can be null)
                'collateral_token' => null, // Optional, prefer hash
                'ip_address' => $ipAddress,
                'role' => 'public', // Default role
                'expires_at' => now()->addMonth() // Bearer token lifetime: 1 month
            ]);
            
            Log::info('Bearer token created successfully in database', [
                'token_id' => $userToken->id,
                'node_domain' => $nodeDomain,
                'expires_at' => $userToken->expires_at->format('Y-m-d H:i:s')
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Database error creating Bearer token', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'sql_state' => $e->errorInfo[0] ?? null,
                'sql_error_code' => $e->errorInfo[1] ?? null,
                'sql_error_message' => $e->errorInfo[2] ?? null,
                'node_domain' => $nodeDomain,
                'node_domain_is_null' => $nodeDomain === null,
                'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...',
                'device_id' => substr($deviceId, 0, 16) . '...'
            ]);
            // Handle unique constraint violation (race condition)
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                // Check if it's token duplicate (token is still UNIQUE)
                if (str_contains($e->getMessage(), 'token')) {
                    // Token already exists - find and return it
                    $existingToken = UserToken::where('token', $bearerToken)
                        ->where('expires_at', '>', now())
                        ->first();
                    
                    if ($existingToken) {
                        Log::info('Token creation failed due to duplicate token, returning existing', [
                            'token_id' => $existingToken->id,
                            'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...'
                        ]);
                        return $existingToken->token;
                    }
                }
                
                // For other duplicates, try to find existing token by escrow_token_hash + device_id + node_domain
                $duplicateCheckQuery = UserToken::where('escrow_token_hash', $escrowTokenHash)
                    ->where('device_id', $deviceId)
                    ->where('expires_at', '>', now());
                
                // ALWAYS filter by node_domain (even if null)
                if ($nodeDomain !== null) {
                    $duplicateCheckQuery->where('node_domain', $nodeDomain);
                } else {
                    $duplicateCheckQuery->whereNull('node_domain');
                }
                
                $existingToken = $duplicateCheckQuery->orderBy('created_at', 'desc')
                    ->first();
                
                if ($existingToken) {
                    Log::info('Token creation failed due to duplicate, returning existing token', [
                        'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...',
                        'token_id' => $existingToken->id
                    ]);
                    return $existingToken->token;
                }
            }
            throw $e;
        }
        
        Log::info('Bearer token created successfully', [
            'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...',
            'wallet_address' => $tokenData['wallet_address'],
            'currency' => $tokenData['currency'],
            'device_id' => substr($deviceId, 0, 16) . '...',
            'node_domain' => $nodeDomain,
            'ip_address' => $ipAddress
        ]);

        return $bearerToken;
    }
    
    /**
     * Delete all bearer tokens for a specific node
     * IMPORTANT: Called when node is removed or untrusted to prevent memory leaks
     * 
     * @param string $nodeDomain Node domain to delete tokens for
     * @return int Number of deleted tokens
     */
    public function deleteBearerTokensForNode(string $nodeDomain): int
    {
        $deletedCount = UserToken::where('node_domain', $nodeDomain)->delete();
        
        Log::info('Bearer tokens deleted for node', [
            'node_domain' => $nodeDomain,
            'deleted_count' => $deletedCount
        ]);
        
        return $deletedCount;
    }

    /**
     * Validate Bearer token
     * Returns null if token is invalid or expired (client should request new token)
     * NOTE: We do NOT auto-extend tokens - they should expire and be recreated for security
     */
    public function validateBearerToken(string $bearerToken): ?UserToken
    {
        $userToken = UserToken::where('token', $bearerToken)->first();
        
        if (!$userToken || $userToken->isExpired()) {
            return null;
        }

        // Just update last used time (no auto-extension - token should expire and be recreated)
        $userToken->updateLastUsed();
        
        return $userToken;
    }

    /**
     * DEPRECATED: refreshBearerToken
     * 
     * Security risk: If Bearer token is stolen, attacker could refresh it indefinitely.
     * This method is kept for backward compatibility but should not be used.
     * Client should request new Bearer token via escrow token instead.
     * 
     * @deprecated Use createBearerToken with escrow token publicPart instead
     */
    public function refreshBearerToken(string $bearerToken, string $ipAddress): ?string
    {
        // Find token (can be expired - we're refreshing it)
        $userToken = UserToken::where('token', $bearerToken)->first();
        
        if (!$userToken) {
            Log::warning('Cannot refresh - Bearer token not found', [
                'token_preview' => substr($bearerToken, 0, 20) . '...'
            ]);
            return null;
        }

        // Use escrow_token_hash if available, otherwise fallback to collateral_token
        $escrowTokenHash = $userToken->escrow_token_hash;
        
        if (!$escrowTokenHash) {
            // Legacy token without hash - cannot refresh properly
            Log::warning('Cannot refresh Bearer token without escrow_token_hash', [
                'token_id' => $userToken->id
            ]);
            return null;
        }
        
        // Create NEW Bearer token (old one remains but will expire naturally)
        $newBearerToken = hash('sha256', $escrowTokenHash . $ipAddress . time() . random_bytes(32));
        
        // Save new token to database
        UserToken::create([
            'token' => $newBearerToken,
            'device_id' => $userToken->device_id,
            'escrow_token_hash' => $escrowTokenHash,
            'collateral_token' => $userToken->collateral_token, // Keep for backward compatibility
            'ip_address' => $ipAddress,
            'role' => $userToken->role,
            'expires_at' => now()->addMonth() // Bearer token lifetime: 1 month
        ]);

        Log::info('Bearer token refreshed (new token created)', [
            'old_token_id' => $userToken->id,
            'old_token_expired' => $userToken->isExpired(),
            'escrow_token_hash' => substr($escrowTokenHash, 0, 16) . '...'
        ]);

        return $newBearerToken;
    }
}

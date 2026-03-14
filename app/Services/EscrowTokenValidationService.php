<?php

namespace App\Services;

use App\Models\EscrowTokenCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class EscrowTokenValidationService
{
    /**
     * Validate escrow token (publicPart)
     * 
     * Simple validation process:
     * 1. Check cache first (if fresh, return cached data)
     * 2. If cache is stale or missing, validate via Core API
     * 3. Update cache with validation result
     * 
     * This approach significantly simplifies the codebase compared to Bearer tokens:
     * - No token expiration management
     * - No refresh logic
     * - No token versioning
     * - Easier future migrations
     * 
     * @param string $publicPart JSON string with public part of escrow token
     * @param string|null $deviceId Device ID (optional, for statistics)
     * @param string|null $ipAddress IP address (optional, for statistics)
     * @return array ['valid' => bool, 'token_data' => array, 'cache' => EscrowTokenCache]
     */
    public function validateToken(string $publicPart, ?string $deviceId = null, ?string $ipAddress = null): array
    {
        try {
            Log::debug('EscrowTokenValidationService: Starting validation', [
                'public_part_length' => strlen($publicPart),
                'public_part_preview' => substr($publicPart, 0, 200) . '...',
                'device_id' => substr($deviceId ?? '', 0, 16) . '...'
            ]);

            // Get or create cache entry
            try {
                $cache = EscrowTokenCache::findOrCreate($publicPart, $deviceId, $ipAddress);
                Log::debug('EscrowTokenValidationService: Cache entry found/created', [
                    'token_hash' => substr($cache->token_hash, 0, 16) . '...',
                    'last_validated_at' => $cache->last_validated_at?->format('Y-m-d H:i:s'),
                    'was_just_created' => !$cache->last_validated_at
                ]);
            } catch (\Exception $e) {
                Log::error('EscrowTokenValidationService: Failed to find/create cache entry', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                throw $e;
            }
            
            // Extract wallet_address from publicPart
            $tokenData = json_decode($publicPart, true);
            $walletAddress = $tokenData['wallet_address'] ?? '';
            
            // If token exists in cache (was validated before) - return valid
            // No re-validation needed - if token is in table, it's valid
            if ($cache->last_validated_at) {
                Log::debug('Escrow token found in cache - returning as valid', [
                    'token_hash' => substr($cache->token_hash, 0, 16) . '...',
                    'last_validated_at' => $cache->last_validated_at->format('Y-m-d H:i:s')
                ]);
                
                return [
                    'valid' => true,
                    'token_data' => [
                        'wallet_address' => $walletAddress,
                        'currency' => $cache->currency,
                        'node_domain' => $cache->node_domain,
                        'environment' => $cache->environment,
                        'token_hash' => $cache->token_hash,
                    ],
                    'cache' => $cache
                ];
            }
            
            // Token not in cache (just created) - validate via Core API
            Log::info('Token not in cache - validating via Core API', [
                'token_hash' => substr($cache->token_hash, 0, 16) . '...'
            ]);
            
            $coreValidation = $this->validateViaCoreApi($publicPart);
            
            if ($coreValidation['valid']) {
                // Mark as validated and add to cache
                $cache->markAsValidated();
                
                Log::info('Escrow token validated successfully via Core API and added to cache', [
                    'token_hash' => substr($cache->token_hash, 0, 16) . '...'
                ]);
            } else {
                Log::warning('Escrow token validation failed via Core API', [
                    'token_hash' => substr($cache->token_hash, 0, 16) . '...',
                    'error' => $coreValidation['error'] ?? 'Unknown error'
                ]);
            }
            
            $walletAddress = $coreValidation['token_data']['wallet_address'] ?? $walletAddress;
            $coreTokenData = $coreValidation['token_data'] ?? [];
            $tokenData = array_merge([
                'wallet_address' => $walletAddress,
                'currency' => $cache->currency,
                'node_domain' => $cache->node_domain,
                'environment' => $cache->environment,
                'token_hash' => $cache->token_hash,
            ], is_array($coreTokenData) ? $coreTokenData : []);

            return [
                'valid' => $coreValidation['valid'],
                'token_data' => $tokenData,
                'cache' => $cache,
                'error' => $coreValidation['error'] ?? null
            ];
            
        } catch (\Exception $e) {
            Log::error('Escrow token validation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'token_data' => null,
                'cache' => null
            ];
        }
    }

    /**
     * Get Core API URL and key for the given escrow token (by token environment).
     * Use this when calling any Core endpoint that operates on this token (reservations, set-transaction-id, cancel-reservation, validate).
     * Same logic as validateViaCoreApi; see docs/ESCROW_TOKEN_AUTHENTICATION.md.
     *
     * @param string $escrowToken Full or public part of escrow token (JSON with environment field)
     * @return array [0 => string url, 1 => string key]
     */
    public function getCoreUrlAndKeyForToken(string $escrowToken): array
    {
        $tokenData = json_decode($escrowToken, true);
        $environment = (is_array($tokenData) && isset($tokenData['environment']))
            ? $tokenData['environment']
            : 'sandbox';
        if (strtolower((string) $environment) === 'prod') {
            $environment = 'production';
        }
        $isProduction = ($environment === 'production');
        $coreUrl = $isProduction
            ? config('services.core_production.url')
            : config('services.core.url');
        if (empty($coreUrl)) {
            $coreUrl = $isProduction ? 'https://api.core.xdexboard.com' : 'https://sandbox.api.core.xdexboard.com';
        }
        $coreKey = $isProduction
            ? (string) config('services.core_production.key')
            : (string) config('services.core.key');
        return [rtrim((string) $coreUrl, '/'), $coreKey];
    }

    /**
     * Validate token via Core API
     *
     * @param string $publicPart JSON string with public part of escrow token
     * @return array ['valid' => bool, 'token_data' => array, 'error' => string|null]
     */
    private function validateViaCoreApi(string $publicPart): array
    {
        try {
            // Extract token data from publicPart first to get environment
            $tokenData = json_decode($publicPart, true);
            if (!$tokenData) {
                return [
                    'valid' => false,
                    'error' => 'Invalid token format: not valid JSON',
                    'token_data' => null
                ];
            }

            // Get token_hash from publicPart (it's already there from Core API)
            if (!isset($tokenData['token_hash'])) {
                return [
                    'valid' => false,
                    'error' => 'Invalid token format: token_hash is missing',
                    'token_data' => null
                ];
            }

            $tokenHash = strtolower(trim($tokenData['token_hash']));

            [$coreUrl, ] = $this->getCoreUrlAndKeyForToken($publicPart);
            $environment = $tokenData['environment'] ?? 'sandbox';
            if (strtolower((string) $environment) === 'prod') {
                $environment = 'production';
            }

            $validateUrl = "$coreUrl/api/escrow-tokens/validate";
            // Core API теперь принимает открытую часть токена (escrow_token), а не token_hash
            $requestBody = [
                'escrow_token' => $publicPart
            ];

            Log::info('Validating token via Core API', [
                'token_hash' => substr($tokenHash, 0, 16) . '...',
                'environment' => $environment, // From token (normalized: 'prod' -> 'production'), not from HTTP_HOST
                'environment_raw' => $tokenData['environment'] ?? 'missing', // Original value from token
                'core_url' => $coreUrl,
                'validate_url' => $validateUrl,
                'currency_from_token' => $tokenData['currency'] ?? 'unknown',
                'public_part_preview' => substr($publicPart, 0, 200) . '...'
            ]);
            
            // Call Core API to validate token - Core expects token_hash, not escrow_token
            $response = Http::timeout(10)->post($validateUrl, $requestBody);
            
            $responseBody = $response->body();
            Log::debug('Core API validation response', [
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $responseBody
            ]);
            
            if (!$response->successful()) {
                Log::error('Core API validation failed', [
                    'status' => $response->status(),
                    'token_hash' => substr($tokenHash, 0, 16) . '...',
                    'environment' => $environment,
                    'core_url' => $coreUrl,
                    'validate_url' => $validateUrl,
                    'response_body' => $responseBody,
                    'currency_from_token' => $tokenData['currency'] ?? 'unknown',
                    'public_part_preview' => substr($publicPart, 0, 200) . '...'
                ]);
                
                return [
                    'valid' => false,
                    'error' => 'Core API validation failed: HTTP ' . $response->status(),
                    'token_data' => null
                ];
            }
            
            $data = $response->json();
            
            if (!isset($data['valid']) || !$data['valid']) {
                return [
                    'valid' => false,
                    'error' => $data['error'] ?? 'Token validation failed',
                    'token_data' => null
                ];
            }
            
            return [
                'valid' => true,
                'token_data' => $data['token_data'] ?? null,
                'error' => null
            ];
            
        } catch (\Exception $e) {
            Log::error('Core API validation error', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'valid' => false,
                'error' => 'Core API unavailable: ' . $e->getMessage(),
                'token_data' => null
            ];
        }
    }
}


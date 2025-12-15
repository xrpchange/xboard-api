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
                    'needs_revalidation' => $cache->needsRevalidation()
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
            
            // If cache is fresh (validated less than 1 hour ago), return cached data
            if (!$cache->needsRevalidation()) {
                Log::debug('Escrow token validated from cache', [
                    'token_hash' => substr($cache->token_hash, 0, 16) . '...',
                    'last_validated_at' => $cache->last_validated_at?->format('Y-m-d H:i:s')
                ]);
                
                // Extract wallet_address from publicPart (publicPart comes from client request)
                $tokenData = json_decode($publicPart, true);
                $walletAddress = $tokenData['wallet_address'] ?? '';
                
                return [
                    'valid' => true,
                    'token_data' => [
                        'wallet_address' => $walletAddress,
                        'currency' => $cache->currency,
                        'node_domain' => $cache->node_domain,
                        'environment' => $cache->environment
                    ],
                    'cache' => $cache
                ];
            }
            
            // Cache is stale or never validated - check with Core API
            Log::info('Validating escrow token via Core API', [
                'token_hash' => substr($cache->token_hash, 0, 16) . '...'
            ]);
            
            $coreValidation = $this->validateViaCoreApi($publicPart);
            
            if ($coreValidation['valid']) {
                // Mark as validated
                $cache->markAsValidated();
                
                Log::info('Escrow token validated successfully via Core API', [
                    'token_hash' => substr($cache->token_hash, 0, 16) . '...'
                ]);
            } else {
                Log::warning('Escrow token validation failed via Core API', [
                    'token_hash' => substr($cache->token_hash, 0, 16) . '...',
                    'error' => $coreValidation['error'] ?? 'Unknown error'
                ]);
            }
            
            // Extract wallet_address from publicPart (publicPart comes from client request)
            $tokenData = json_decode($publicPart, true);
            $walletAddress = $coreValidation['token_data']['wallet_address'] ?? ($tokenData['wallet_address'] ?? '');
            
            return [
                'valid' => $coreValidation['valid'],
                'token_data' => $coreValidation['token_data'] ?? [
                    'wallet_address' => $walletAddress,
                    'currency' => $cache->currency,
                    'node_domain' => $cache->node_domain,
                    'environment' => $cache->environment
                ],
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
     * Validate token via Core API
     * 
     * @param string $publicPart JSON string with public part of escrow token
     * @return array ['valid' => bool, 'token_data' => array, 'error' => string|null]
     */
    private function validateViaCoreApi(string $publicPart): array
    {
        try {
            // Get Core API URL from config
            $coreUrl = config('app.core_api_url', 'https://sandbox.api.core.xdexboard.com');
            
            // Call Core API to validate token
            $response = Http::timeout(10)->post("$coreUrl/api/escrow-tokens/validate", [
                'escrow_token' => $publicPart
            ]);
            
            if (!$response->successful()) {
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


<?php

namespace App\Services;

use App\Models\EscrowTokenCache;
use App\Models\Node;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class EscrowTokenValidationService
{
    private function normalizeEnvironment(string $environment): string
    {
        $v = strtolower(trim($environment));
        if ($v === 'prod') {
            return 'production';
        }
        if ($v === 'development' || $v === 'dev') {
            return 'sandbox';
        }
        return $v !== '' ? $v : 'sandbox';
    }

    private function normalizeDomain(string $domain): string
    {
        $v = strtolower(trim($domain));
        $v = preg_replace('#^https?://#', '', $v) ?? $v;
        return rtrim($v, '/');
    }

    private function domainsMatch(string $a, string $b): bool
    {
        $left = $this->normalizeDomain($a);
        $right = $this->normalizeDomain($b);
        if ($left === '' || $right === '') {
            return false;
        }
        return $left === $right || str_ends_with($left, '.'.$right) || str_ends_with($right, '.'.$left);
    }

    private function endpointUrl(array $endpoints, string $key): ?string
    {
        $value = $endpoints[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return rtrim(trim($value), '/');
        }
        if (is_array($value)) {
            $url = $value['url'] ?? null;
            if (is_string($url) && trim($url) !== '') {
                return rtrim(trim($url), '/');
            }
        }
        return null;
    }

    private function deriveCoreUrlsFromTokenNodeDomain(string $tokenNodeDomain, bool $isProduction): array
    {
        $domain = $this->normalizeDomain($tokenNodeDomain);
        if ($domain === '') {
            return [];
        }

        // token node_domain can be "xrpchange.site", "api.xrpchange.site", "sandbox.api.xrpchange.site", etc.
        $base = preg_replace('/^(dev\.api\.|sandbox\.api\.|api\.)/i', '', $domain) ?: $domain;
        $base = trim((string) $base);
        if ($base === '') {
            return [];
        }

        $candidates = $isProduction
            ? [
                'https://api.core.' . $base,
                'https://core.' . $base,
            ]
            : [
                'https://sandbox.api.core.' . $base,
                'https://dev.api.core.' . $base,
                'https://core.' . $base,
            ];

        $urls = [];
        foreach ($candidates as $url) {
            $normalized = rtrim(trim($url), '/');
            if ($normalized !== '' && !in_array($normalized, $urls, true)) {
                $urls[] = $normalized;
            }
        }

        return $urls;
    }

    private function coreUrlCandidatesForToken(array $tokenData, bool $isProduction): array
    {
        $tokenNodeDomain = isset($tokenData['node_domain']) ? trim((string) $tokenData['node_domain']) : '';
        $urls = [];
        $appendUrl = static function (?string $url) use (&$urls): void {
            if (!is_string($url) || trim($url) === '') {
                return;
            }
            $normalized = rtrim(trim($url), '/');
            if ($normalized !== '' && !in_array($normalized, $urls, true)) {
                $urls[] = $normalized;
            }
        };

        $endpointCandidates = $isProduction
            ? ['core_production', 'core_api', 'core']
            : ['core_sandbox', 'core_dev', 'core_api', 'core'];

        if ($tokenNodeDomain !== '') {
            foreach ($this->deriveCoreUrlsFromTokenNodeDomain($tokenNodeDomain, $isProduction) as $derivedUrl) {
                $appendUrl($derivedUrl);
            }

            $normalizedTokenDomain = $this->normalizeDomain($tokenNodeDomain);
            $nodes = Node::query()->where('is_active', true)->get(['domain', 'endpoints']);
            $exactMatches = [];
            $fuzzyMatches = [];
            foreach ($nodes as $node) {
                $nodeDomain = (string) ($node->domain ?? '');
                if ($nodeDomain === '') {
                    continue;
                }
                $normalizedNodeDomain = $this->normalizeDomain($nodeDomain);
                if ($normalizedNodeDomain === $normalizedTokenDomain) {
                    $exactMatches[] = $node;
                    continue;
                }
                if ($this->domainsMatch($tokenNodeDomain, $nodeDomain)) {
                    $fuzzyMatches[] = $node;
                }
            }

            foreach (array_merge($exactMatches, $fuzzyMatches) as $node) {
                $endpoints = is_array($node->endpoints) ? $node->endpoints : [];
                foreach ($endpointCandidates as $candidate) {
                    $appendUrl($this->endpointUrl($endpoints, $candidate));
                }
            }
        }

        $appendUrl($isProduction ? config('services.core_production.url') : config('services.core.url'));
        $appendUrl($isProduction ? 'https://api.core.xdexboard.com' : 'https://sandbox.api.core.xdexboard.com');

        return $urls;
    }

    private function resolveCoreUrlForToken(array $tokenData, bool $isProduction): ?string
    {
        $candidates = $this->coreUrlCandidatesForToken($tokenData, $isProduction);
        return $candidates[0] ?? null;
    }

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
        $environment = $this->normalizeEnvironment((string) ((is_array($tokenData) && isset($tokenData['environment']))
            ? $tokenData['environment']
            : 'sandbox'));
        $isProduction = ($environment === 'production');
        $coreUrl = is_array($tokenData) ? $this->resolveCoreUrlForToken($tokenData, $isProduction) : null;
        if (empty($coreUrl)) {
            $coreUrl = $isProduction
                ? config('services.core_production.url')
                : config('services.core.url');
        }
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

            $environment = $this->normalizeEnvironment((string) ($tokenData['environment'] ?? 'sandbox'));
            $isProduction = ($environment === 'production');
            $coreUrls = $this->coreUrlCandidatesForToken($tokenData, $isProduction);
            if (empty($coreUrls)) {
                [$fallbackCoreUrl, ] = $this->getCoreUrlAndKeyForToken($publicPart);
                $coreUrls = [rtrim((string) $fallbackCoreUrl, '/')];
            }

            // Compatibility payload:
            // - newer Core validates by escrow_token (public JSON)
            // - some deployments still expect token_hash
            $requestBody = [
                'escrow_token' => $publicPart,
                'token_hash' => $tokenHash,
            ];

            Log::info('Validating token via Core API', [
                'token_hash' => substr($tokenHash, 0, 16) . '...',
                'environment' => $environment,
                'environment_raw' => $tokenData['environment'] ?? 'missing',
                'core_urls' => $coreUrls,
                'currency_from_token' => $tokenData['currency'] ?? 'unknown',
                'public_part_preview' => substr($publicPart, 0, 200) . '...'
            ]);

            $lastError = null;
            foreach ($coreUrls as $coreUrl) {
                $validateUrl = rtrim($coreUrl, '/') . '/api/escrow-tokens/validate';
                $response = Http::timeout(10)->post($validateUrl, $requestBody);
                $responseBody = $response->body();

                Log::debug('Core API validation response', [
                    'status' => $response->status(),
                    'headers' => $response->headers(),
                    'body' => $responseBody,
                    'core_url' => $coreUrl
                ]);

                if (!$response->successful()) {
                    $responseJson = $response->json();
                    $coreMessage = is_array($responseJson)
                        ? (($responseJson['message'] ?? $responseJson['error'] ?? null))
                        : null;
                    $coreMessage = is_string($coreMessage) ? trim($coreMessage) : null;
                    $lastError = $coreMessage
                        ? ('Core API validation failed: HTTP ' . $response->status() . ' - ' . $coreMessage)
                        : ('Core API validation failed: HTTP ' . $response->status());
                    Log::warning('Core API validation attempt failed', [
                        'status' => $response->status(),
                        'token_hash' => substr($tokenHash, 0, 16) . '...',
                        'environment' => $environment,
                        'core_url' => $coreUrl,
                        'validate_url' => $validateUrl,
                        'response_body' => $responseBody,
                        'core_message' => $coreMessage
                    ]);
                    continue;
                }

                $data = $response->json();
                if (!isset($data['valid']) || !$data['valid']) {
                    $lastError = $data['error'] ?? 'Token validation failed';
                    Log::warning('Core API returned valid=false', [
                        'token_hash' => substr($tokenHash, 0, 16) . '...',
                        'core_url' => $coreUrl,
                        'error' => $lastError
                    ]);
                    continue;
                }

                return [
                    'valid' => true,
                    'token_data' => $data['token_data'] ?? null,
                    'error' => null
                ];
            }

            return [
                'valid' => false,
                'error' => $lastError ?? 'Core API validation failed on all configured endpoints',
                'token_data' => null
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


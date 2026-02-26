<?php

namespace App\Services;

use App\Models\CryptoCurrency;
use App\Models\ExchangeLot;
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
     * Get or create exchange lot based on collateral token.
     * @param string $publicPart Full public part of escrow token (JSON)
     * @param string $currency Currency code (XRP, XMR, etc.)
     */
    public function getOrCreateExchangeLot(string $publicPart, string $currency = 'XRP'): ExchangeLot
    {
        $cryptoCurrency = CryptoCurrency::byCode($currency)->first();
        if (!$cryptoCurrency) {
            throw new \Exception('Unsupported currency: ' . $currency);
        }

        $walletHash = hash('sha256', $publicPart);
        $exchangeLot = ExchangeLot::where('wallet_hash', $walletHash)
            ->where('crypto_currency_id', $cryptoCurrency->id)
            ->where('wallet_address', $publicPart)
            ->first();

        if (!$exchangeLot) {
            $exchangeLot = ExchangeLot::create([
                'wallet_address' => $publicPart,
                'wallet_hash' => $walletHash,
                'crypto_currency_id' => $cryptoCurrency->id,
                'is_test' => false,
                'test_group' => null
            ]);
        }

        return $exchangeLot;
    }
}

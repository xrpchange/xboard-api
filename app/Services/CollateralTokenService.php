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
     */
    public function checkTokenBalance(string $token): array
    {
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
            'environment' => $isDev ? 'dev-' . config('services.dev.mode', 'sandbox') : ($isProduction ? 'production' : 'sandbox')
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

            // New API endpoint with crypto_type
            $response = $request->post($coreUrl . '/api/escrow-tokens/balance', [
                'crypto_type' => 'xrp',
                'escrow_token' => $token,
            ]);

            if (!$response->successful()) {
                throw new \Exception('Core request failed with status: ' . $response->status());
            }

            $data = $response->json();
            
            if (!isset($data['total'])) {
                Log::warning('Token balance response missing total');
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
     * Decrypt collateral token and get data
     */
    public function decryptToken(string $token): array
    {
        try {
            $tokenData = json_decode($token, true);
            
            if (!isset($tokenData['version'], $tokenData['wallet_address'], $tokenData['currency'])) {
                throw new \Exception('Invalid token structure');
            }

            if ($tokenData['currency'] !== 'XRP') {
                throw new \Exception('Unsupported currency: ' . $tokenData['currency']);
            }

            return [
                'wallet_address' => $tokenData['wallet_address'],
                'currency' => $tokenData['currency'],
                'mode' => $tokenData['mode'] ?? 'prod'
            ];
        } catch (\Exception $e) {
            Log::error('Token decryption failed', [
                'token' => substr($token, 0, 50) . '...',
                'error' => $e->getMessage()
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
     * Create Bearer token after successful balance check
     */
    public function createBearerToken(string $collateralToken, string $ipAddress): string
    {
        // Decrypt collateral token
        $tokenData = $this->decryptToken($collateralToken);
        
        // Check balance via core
        $balanceData = $this->checkTokenBalance($collateralToken);
        
        // Get or create exchange lot
        $exchangeLot = $this->getOrCreateExchangeLot($tokenData['wallet_address'], $tokenData['currency']);
        
        // Generate unique token
        $bearerToken = hash('sha256', $collateralToken . $ipAddress . time() . random_bytes(32));
        
        // Save token to database
        UserToken::create([
            'token' => $bearerToken,
            'device_id' => $tokenData['wallet_address'], // Use wallet address as device_id
            'collateral_token' => $collateralToken,
            'ip_address' => $ipAddress,
            'expires_at' => now()->addYear()
        ]);

        return $bearerToken;
    }

    /**
     * Validate Bearer token
     */
    public function validateBearerToken(string $bearerToken): ?UserToken
    {
        $userToken = UserToken::where('token', $bearerToken)->first();
        
        if (!$userToken || $userToken->isExpired()) {
            return null;
        }

        // Update last used time
        $userToken->updateLastUsed();
        
        return $userToken;
    }
}

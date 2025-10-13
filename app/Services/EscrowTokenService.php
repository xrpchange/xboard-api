<?php

namespace App\Services;

use App\Exceptions\InvalidTokenException;
use App\Exceptions\UnsupportedCurrencyException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class EscrowTokenService
{
    public function __construct() {}

    /**
     * Create escrow token through core API
     * Stage 1: Get open part of token
     */
    public function createToken(string $cryptoType, float $amount = 0.0): array
    {
        // Determine Core URL based on current API host (preserve environment)
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $coreUrl = config('services.core.url'); // default (sandbox)
        
        // If request came to production API, use production Core
        if (!preg_match('/^(sandbox\.|dev\.)/', $host)) {
            $coreUrl = config('services.core_production.url');
        }
        
        $coreKey = config('services.core.key');
        $timeout = config('services.core.timeout', 10);

        try {
            $request = Http::timeout($timeout);
            
            if (!empty($coreKey)) {
                $request = $request->withHeaders([
                    'X-Core-Key' => $coreKey,
                ]);
            }
            
            $response = $request->post($coreUrl . '/api/escrow-tokens/create', [
                'crypto_type' => $cryptoType,
                'amount' => $amount
            ]);

            if (!$response->successful()) {
                $errorData = $response->json();
                $errorMessage = $errorData['message'] ?? 'Token creation failed';
                throw new \Exception($errorMessage);
            }

            return $response->json();
        } catch (\Throwable $e) {
            throw new \Exception('Failed to create escrow token: ' . $e->getMessage());
        }
    }

    /**
     * Validate escrow token through core using token_hash
     */
    public function validateToken(string $token): array
    {
        $coreUrl = config('services.core.url');
        $coreKey = config('services.core.key');
        $timeout = config('services.core.timeout', 10);

        // Decode token to get token_hash and wallet_address
        $tokenDecoded = json_decode($token, true);
        if (!$tokenDecoded || !isset($tokenDecoded['token_hash']) || !isset($tokenDecoded['wallet_address'])) {
            throw new InvalidTokenException('Invalid token format: missing token_hash or wallet_address');
        }

        try {
            $request = Http::timeout($timeout);
            
            if (!empty($coreKey)) {
                $request = $request->withHeaders([
                    'X-Core-Key' => $coreKey,
                ]);
            }

            // Валидация через новый endpoint /api/escrow-tokens/validate
            $response = $request->post($coreUrl . '/api/escrow-tokens/validate', [
                'token_hash' => $tokenDecoded['token_hash'],
            ]);

            if (!$response->successful()) {
                throw new InvalidTokenException('Token is invalid or unavailable');
            }

            $data = $response->json();
            
            // Проверяем, что токен валиден
            if (!isset($data['valid']) || $data['valid'] !== true) {
                throw new InvalidTokenException('Token validation failed');
            }
        } catch (\Throwable $e) {
            throw new InvalidTokenException('Token is invalid or unavailable: ' . $e->getMessage());
        }
        
        return [
            'wallet_address' => $tokenDecoded['wallet_address'],
            'token_hash' => $tokenDecoded['token_hash'],
            'crypto_type' => $data['data']['crypto_type'] ?? null,
            'expires_at' => $data['data']['expires_at'] ?? null
        ];
    }

    /**
     * Get available cryptocurrencies from Core API
     */
    public function getAvailableCryptos(): array
    {
        // Determine Core URL based on current API host (preserve environment)
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $coreUrl = config('services.core.url'); // default (sandbox)
        
        // If request came to production API, use production Core
        if (!preg_match('/^(sandbox\.|dev\.)/', $host)) {
            $coreUrl = config('services.core_production.url');
        }
        
        $coreKey = config('services.core.key');
        $timeout = config('services.core.timeout', 10);

        try {
            $request = Http::timeout($timeout);
            
            if (!empty($coreKey)) {
                $request = $request->withHeaders([
                    'X-Core-Key' => $coreKey,
                ]);
            }

            $response = $request->get($coreUrl . '/api/info');

            if (!$response->successful()) {
                throw new \Exception('Failed to get cryptocurrencies from Core');
            }

            return $response->json();
        } catch (\Throwable $e) {
            throw new \Exception('Failed to get available cryptocurrencies: ' . $e->getMessage());
        }
    }

    /**
     * Get crypto currency ID from token
     */
    public function getCryptoCurrencyId(string $token): int
    {
        $tokenDecoded = json_decode($token, true);
        
        if (!$tokenDecoded || !isset($tokenDecoded['currency'])) {
            throw new InvalidTokenException('Invalid token format');
        }
        
        // Get crypto currency ID by code
        $cryptoCurrency = DB::table('crypto_currencies')
            ->where('code', $tokenDecoded['currency'])
            ->where('is_active', true)
            ->first();
            
        if (!$cryptoCurrency) {
            throw new UnsupportedCurrencyException($tokenDecoded['currency']);
        }
        
        return $cryptoCurrency->id;
    }
}

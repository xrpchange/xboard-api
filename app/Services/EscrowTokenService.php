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
     * Validate escrow token through core
     */
    public function validateToken(string $token): array
    {
        $coreUrl = config('services.core.url');
        $coreKey = config('services.core.key');
        $timeout = config('services.core.timeout', 10);

        try {
            $request = Http::timeout($timeout);
            
            if (!empty($coreKey)) {
                $request = $request->withHeaders([
                    'X-Core-Key' => $coreKey,
                ]);
            }

            $response = $request->post($coreUrl . '/api/tokens/balance', [
                'token' => $token,
            ]);

            if (!$response->successful()) {
                throw new InvalidTokenException('Token is invalid or unavailable');
            }

            $data = $response->json();
        } catch (\Throwable $e) {
            throw new InvalidTokenException('Token is invalid or unavailable');
        }
        
        // Decode token to get wallet_address
        $tokenDecoded = json_decode($token, true);
        if (!$tokenDecoded || !isset($tokenDecoded['wallet_address'])) {
            throw new InvalidTokenException('Invalid token format');
        }
        
        return [
            'wallet_address' => $tokenDecoded['wallet_address'],
            'balance' => $data['data'] ?? $data
        ];
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

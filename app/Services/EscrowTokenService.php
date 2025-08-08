<?php

namespace App\Services;

use App\Exceptions\InvalidTokenException;
use App\Exceptions\UnsupportedCurrencyException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class EscrowTokenService
{
    /**
     * Validate escrow token through core
     */
    public function validateToken(string $token): array
    {
        $coreUrl = config('app.env') === 'production' 
            ? 'http://api.core.xrpchange.site/api/tokens/balance'
            : 'http://sandbox.api.core.xrpchange.site/api/tokens/balance';
            
        $response = Http::post($coreUrl, [
            'token' => $token
        ]);
        
        if (!$response->successful()) {
            throw new InvalidTokenException('Token is invalid or unavailable');
        }
        
        $data = $response->json();
        
        // Decode token to get wallet_address
        $tokenDecoded = json_decode($token, true);
        if (!$tokenDecoded || !isset($tokenDecoded['wallet_address'])) {
            throw new InvalidTokenException('Invalid token format');
        }
        
        return [
            'wallet_address' => $tokenDecoded['wallet_address'],
            'balance' => $data['data'] ?? []
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

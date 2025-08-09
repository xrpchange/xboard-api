<?php

namespace App\Services;

use App\Exceptions\InvalidTokenException;
use App\Exceptions\UnsupportedCurrencyException;
use Illuminate\Support\Facades\DB;

class EscrowTokenService
{
    private CoreClient $coreClient;

    public function __construct(CoreClient $coreClient)
    {
        $this->coreClient = $coreClient;
    }

    /**
     * Validate escrow token through core
     */
    public function validateToken(string $token): array
    {
        try {
            $data = $this->coreClient->checkTokenBalance($token);
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

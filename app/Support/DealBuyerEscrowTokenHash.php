<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Buyer escrow token hash from send-message request (body + X-Escrow-Token header).
 */
final class DealBuyerEscrowTokenHash
{
    public static function fromRequest(Request $request): string
    {
        $tokenData = $request->input('escrow_token_data', []);
        if (is_array($tokenData) && isset($tokenData['token_hash'])) {
            $fromData = strtolower(trim((string) $tokenData['token_hash']));
            if ($fromData !== '') {
                return $fromData;
            }
        }
        $buyerToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        if (! is_string($buyerToken) || trim($buyerToken) === '') {
            return '';
        }
        $decoded = json_decode($buyerToken, true);
        if (! is_array($decoded) || ! isset($decoded['token_hash'])) {
            return '';
        }

        return strtolower(trim((string) $decoded['token_hash']));
    }
}

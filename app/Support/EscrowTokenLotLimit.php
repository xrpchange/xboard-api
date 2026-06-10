<?php

namespace App\Support;

use App\Models\ExchangeLot;
use Illuminate\Http\JsonResponse;

/**
 * Caps how many exchange_lots rows may exist per seller escrow token (wallet_hash).
 */
final class EscrowTokenLotLimit
{
    public static function maxPerToken(): int
    {
        return max(0, (int) config('lot.max_lots_per_escrow_token', 10));
    }

    public static function countForWalletHash(string $walletHash): int
    {
        $hash = strtolower(trim($walletHash));
        if ($hash === '') {
            return 0;
        }

        return (int) ExchangeLot::query()
            ->where('wallet_hash', $hash)
            ->count();
    }

    public static function isLimitReached(string $walletHash): bool
    {
        $max = self::maxPerToken();
        if ($max <= 0) {
            return false;
        }

        return self::countForWalletHash($walletHash) >= $max;
    }

    public static function limitReachedResponse(string $walletHash): JsonResponse
    {
        $max = self::maxPerToken();
        $count = self::countForWalletHash($walletHash);

        return response()->json([
            'success' => false,
            'message' => "Escrow token lot limit reached: {$count}/{$max} lots for this token.",
            'error_code' => 'escrow_token_lot_limit_reached',
            'max_lots_per_escrow_token' => $max,
            'current_lot_count' => $count,
        ], 422);
    }
}

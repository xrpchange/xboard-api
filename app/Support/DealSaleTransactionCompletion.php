<?php

namespace App\Support;

use App\Models\ExchangeLot;
use App\Models\Transaction;

/**
 * When the buyer posts deal_completed_buyer in chat, mark in-flight sale rows as completed for lot accounting.
 */
final class DealSaleTransactionCompletion
{
    /**
     * True when deal_tag JSON is buyer completion for this lot (lot_id must match).
     *
     * @param  array<string, mixed>|null  $dealTagData
     */
    public static function dealTagIsBuyerCompletedForLot(?array $dealTagData, ExchangeLot $lot): bool
    {
        if (! is_array($dealTagData)) {
            return false;
        }
        if (($dealTagData['t'] ?? '') !== 'deal_completed_buyer') {
            return false;
        }
        $tagLotId = (string) ($dealTagData['lot_id'] ?? '');

        return $tagLotId !== '' && $tagLotId === (string) $lot->id;
    }

    /**
     * @return int Number of rows updated
     */
    public static function markPendingSaleTransactionsCompleted(ExchangeLot $lot): int
    {
        return Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('tx_type', 'sale')
            ->where('status', 'pending')
            ->update(['status' => 'completed', 'updated_at' => now()]);
    }
}

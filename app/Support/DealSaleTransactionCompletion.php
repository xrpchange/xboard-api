<?php

namespace App\Support;

use App\Models\ExchangeLot;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

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
     * Seller confirms deal completion after the buyer’s step timer expired (buyer did not post deal_completed_buyer).
     *
     * @param  array<string, mixed>|null  $dealTagData
     */
    public static function dealTagIsSellerTimeoutCompletionForLot(?array $dealTagData, ExchangeLot $lot): bool
    {
        if (! is_array($dealTagData)) {
            return false;
        }
        if (($dealTagData['t'] ?? '') !== 'deal_completed_seller_timeout') {
            return false;
        }
        $tagLotId = (string) ($dealTagData['lot_id'] ?? '');

        return $tagLotId !== '' && $tagLotId === (string) $lot->id;
    }

    /**
     * LOT-002: after seller crypto payout the sale row is already {@code completed} while the lot
     * still waits for {@code deal_completed_buyer} or {@code deal_completed_seller_timeout}.
     */
    public static function lotHasCompletedCryptoPayoutSaleWithBuyerStillBound(ExchangeLot $lot): bool
    {
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return false;
        }

        return Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('buyer_lot_email', $buyerEmail)
            ->where('tx_type', 'sale')
            ->where('status', 'completed')
            ->whereNotNull('seller_crypto_payout_tx_hash')
            ->where('seller_crypto_payout_tx_hash', '!=', '')
            ->exists();
    }

    /**
     * @return int Number of rows updated
     */
    public static function markPendingSaleTransactionsCompleted(ExchangeLot $lot): int
    {
        return DB::transaction(function () use ($lot): int {
            $transactions = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('tx_type', 'sale')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($transactions as $transaction) {
                $transaction->status = 'completed';
                $transaction->save();
            }

            return $transactions->count();
        });
    }
}

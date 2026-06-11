<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persistent per-lot deal statistics on exchange_lots (not reset on lot edit).
 */
final class ExchangeLotDealCounters
{
    public static function incrementCompletedDeal(int $lotId): void
    {
        if ($lotId <= 0) {
            return;
        }
        $updated = DB::table('exchange_lots')
            ->where('id', $lotId)
            ->increment('completed_deals_count');
        if ($updated === 0) {
            Log::warning('[exchange-lots] completed_deals_count increment skipped: lot not found', [
                'lot_id' => $lotId,
            ]);
        }
    }

    /**
     * Operator paid buyer from seller escrow reservation after dispute (compensate_buyer / AI auto payout).
     * Not incremented on deny_buyer (favor_seller) — that increments buyer email bad_deals_count instead.
     */
    public static function incrementBadDeal(int $lotId): void
    {
        if ($lotId <= 0) {
            return;
        }
        $updated = DB::table('exchange_lots')
            ->where('id', $lotId)
            ->increment('bad_deals_count');
        if ($updated === 0) {
            Log::warning('[exchange-lots] bad_deals_count increment skipped: lot not found', [
                'lot_id' => $lotId,
            ]);
        }
    }
}

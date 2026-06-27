<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Models\DealDispute;
use App\Models\ExchangeLot;
use App\Models\Transaction;
use App\Support\ApiDeploymentEnvironment;
use App\Support\DealStartedAtUtc;
use Illuminate\Support\Facades\Log;

/**
 * When training lots are listed in search, unlock practice deals abandoned longer than configured minutes.
 */
final class TrainingDealAbandonedLotRelease
{
    public function __construct(
        private readonly TrainingDealSellerActions $sellerActions,
    ) {}

    public function releaseStaleLockedLots(): int
    {
        if (! filter_var(config('training_deal.enabled', false), FILTER_VALIDATE_BOOL)) {
            return 0;
        }
        if (ApiDeploymentEnvironment::current() !== ApiDeploymentEnvironment::SANDBOX) {
            return 0;
        }

        $minutes = max(1, (int) config('training_deal.abandoned_deal_minutes', 15));
        $cutoff = now('UTC')->subMinutes($minutes);

        $lots = ExchangeLot::query()
            ->where('is_training', true)
            ->whereNotNull('buyer_lot_email')
            ->where('buyer_lot_email', '<>', '')
            ->get();

        $released = 0;
        foreach ($lots as $lot) {
            $started = DealStartedAtUtc::forLot($lot);
            if ($started === null || $started->isAfter($cutoff)) {
                continue;
            }
            $this->releaseAbandonedLot($lot);
            $released++;
        }

        if ($released > 0) {
            Log::info('[training-deal] released abandoned locked lots', [
                'count' => $released,
                'abandoned_after_minutes' => $minutes,
            ]);
        }

        return $released;
    }

    private function releaseAbandonedLot(ExchangeLot $lot): void
    {
        $lot->refresh();
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));

        $this->sellerActions->deleteSellerCoreReservationForAbandonedDeal($lot);

        if ($buyerEmail !== '') {
            Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('buyer_lot_email', $buyerEmail)
                ->delete();
        }

        DealDispute::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereIn('status', ['opened', 'in_review'])
            ->update([
                'status' => 'closed',
                'resolved_by' => 'system',
                'resolution' => 'Training deal abandoned (no activity).',
                'resolved_at' => now('UTC'),
                'closed_at' => now('UTC'),
                'last_activity_at' => now('UTC'),
            ]);

        ExchangeLot::query()->where('id', $lot->id)->update([
            'buyer_lot_email' => null,
            'buyer_escrow_token_hash' => null,
            'buyer_selected_amount' => null,
            'buyer_seen_ip' => null,
            'buyer_ip_share_with_seller' => false,
            'sold_crypto_amount' => 0,
        ]);

        Log::info('[training-deal] abandoned lot unlocked for search', [
            'lot_id' => $lot->id,
        ]);
    }
}

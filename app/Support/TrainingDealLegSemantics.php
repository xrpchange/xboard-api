<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * Training practice lots (fiat↔crypto): first leg = to side, second leg = from side.
 * Not the general mixed-pair rule where crypto→fiat first leg can be bank (USD).
 *
 * @see docs/TRAINING_DEAL.md
 */
final class TrainingDealLegSemantics
{
    public static function buyerFirstLegIsBankTransfer(ExchangeLot $lot): ?bool
    {
        if (! (bool) ($lot->is_training ?? false)) {
            return null;
        }
        $to = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'to');

        return match ($to) {
            'fiat' => true,
            'crypto', 'stablecoin' => false,
            default => null,
        };
    }

    public static function buyerSecondLegReceivesCrypto(ExchangeLot $lot): ?bool
    {
        if (! (bool) ($lot->is_training ?? false)) {
            return null;
        }
        $from = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'from');

        return match ($from) {
            'fiat' => false,
            'crypto', 'stablecoin' => true,
            default => null,
        };
    }
}

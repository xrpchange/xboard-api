<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * @deprecated Training lots use the same leg rules as production ({@see DealBuyerFirstLeg}).
 */
final class TrainingDealLegSemantics
{
    public static function buyerFirstLegIsBankTransfer(ExchangeLot $lot): ?bool
    {
        if (! (bool) ($lot->is_training ?? false)) {
            return null;
        }

        return DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot);
    }

    public static function buyerSecondLegReceivesCrypto(ExchangeLot $lot): ?bool
    {
        if (! (bool) ($lot->is_training ?? false)) {
            return null;
        }
        $firstLegBank = DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot);

        return DealBuyerSecondLeg::buyerReceivesCrypto($lot, $firstLegBank);
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * Mixed pair where buyer pays on-chain crypto first and receives fiat on the second leg.
 * Buyer may deposit from any external wallet to the seller escrow token address only;
 * inbound amount is reserved on the seller token until deal completion or refund.
 */
final class CryptoToFiatDealSupport
{
    public static function isCryptoToFiatDeal(ExchangeLot $lot): bool
    {
        $from = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'from');
        $to = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'to');

        return in_array($from, ['crypto', 'stablecoin'], true) && $to === 'fiat';
    }
}

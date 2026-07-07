<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * Second deal leg: seller sends `from_currency` / `from_*_currency_id` type.
 * Mirrors xboard-client {@see ExchangeLot::sellerSecondLegSendsCryptoOrNull}.
 */
final class DealSellerSecondLeg
{
    public static function sendsCrypto(ExchangeLot $lot): ?bool
    {
        $fromType = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'from');
        if ($fromType === null) {
            return null;
        }

        return match ($fromType) {
            'crypto', 'stablecoin' => true,
            'fiat' => false,
            default => null,
        };
    }
}

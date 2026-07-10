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
        if (CryptoToFiatDealSupport::buyerCryptoFirstLegCryptoToFiat($lot)) {
            return false;
        }
        if (CryptoToFiatDealSupport::buyerFiatFirstLegFiatToCrypto($lot)) {
            return false;
        }

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

    /**
     * Seller must have a Core reservation before sending payment details:
     * - full reservation when seller second leg is crypto;
     * - service_fee_only or full lock on mixed fiat↔on-chain pairs (either field orientation).
     */
    public static function requiresPrePaymentDetailsReservation(ExchangeLot $lot): bool
    {
        return self::sendsCrypto($lot) === true
            || CryptoToFiatDealSupport::isCryptoToFiatDeal($lot);
    }
}

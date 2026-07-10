<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * Core seller reservation at send-payment-details (LOT-026).
 *
 * crypto→fiat (buyer pays on-chain first): seller sends bank details (`payment_type=bank`)
 * but still needs `service_fee_only` on the seller escrow token before payment_details.
 */
final class SellerPaymentDetailsReservationPolicy
{
    public static function preReservationRequired(ExchangeLot $lot, string $paymentType): bool
    {
        if (! DealSellerSecondLeg::requiresPrePaymentDetailsReservation($lot)) {
            return false;
        }

        if ($paymentType === 'crypto') {
            return true;
        }

        return $paymentType === 'bank'
            && CryptoToFiatDealSupport::buyerCryptoFirstLegCryptoToFiat($lot);
    }

    public static function serviceFeeOnlyPreReserve(ExchangeLot $lot): bool
    {
        return CryptoToFiatDealSupport::buyerCryptoFirstLegCryptoToFiat($lot);
    }
}

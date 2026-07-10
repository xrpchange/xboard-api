<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * Core seller reservation at send-payment-details (LOT-026).
 *
 * Mixed fiat↔crypto and on-chain seller legs always lock Core before payment_details.
 * `payment_type=bank` covers fiat-first mixed pairs (USD→USDT) and legacy clients that
 * sent bank on crypto→fiat; `payment_type=crypto` covers all other reservation paths.
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

        if ($paymentType !== 'bank') {
            return false;
        }

        // crypto→crypto: bank payment_details is invalid — no reservation.
        if (! CryptoToFiatDealSupport::isCryptoToFiatDeal($lot)) {
            return false;
        }

        // Mixed pair: buyer fiat-first (bank leg 1) or buyer crypto-first (bank wrongly sent on crypto→fiat).
        return DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot) === false
            || CryptoToFiatDealSupport::buyerCryptoFirstLegCryptoToFiat($lot);
    }

    public static function serviceFeeOnlyPreReserve(ExchangeLot $lot): bool
    {
        if (! CryptoToFiatDealSupport::isCryptoToFiatDeal($lot)) {
            return false;
        }

        return DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot) === true;
    }
}

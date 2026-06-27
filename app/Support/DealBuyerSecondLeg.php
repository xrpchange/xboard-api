<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExchangeLot;

/** Mirrors xboard-client ExchangeLot.kt buyer second-leg destination rules. */
final class DealBuyerSecondLeg
{
    public static function buyerReceivesCrypto(ExchangeLot $lot, ?bool $sellerFirstLegWasBankTransfer = null): ?bool
    {
        $fromType = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'from');
        $toType = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'to');
        if ($fromType === null || $toType === null) {
            return null;
        }
        $fromOnChain = in_array($fromType, ['crypto', 'stablecoin'], true);
        $toOnChain = in_array($toType, ['crypto', 'stablecoin'], true);

        if ($sellerFirstLegWasBankTransfer === true) {
            return match (true) {
                $fromOnChain && $toOnChain => true,
                $fromOnChain && $toType === 'fiat' => true,
                $fromType === 'fiat' && $toOnChain => true,
                default => false,
            };
        }
        if ($sellerFirstLegWasBankTransfer === false) {
            return match (true) {
                $fromOnChain && $toOnChain => true,
                $fromOnChain && $toType === 'fiat' => false,
                $fromType === 'fiat' && $toOnChain => false,
                default => false,
            };
        }

        return match (true) {
            $fromOnChain && $toOnChain => true,
            $fromOnChain && $toType === 'fiat' => true,
            $fromType === 'fiat' && $toOnChain => true,
            default => false,
        };
    }
}

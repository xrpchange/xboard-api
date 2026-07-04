<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * Fiat amount for synthetic sandbox bank PDF receipts.
 *
 * `buyer_selected_amount` is always in the lot to_currency (what the buyer pays in UI).
 * Bank receipts must show the fiat leg only — not crypto/stablecoin to_currency amounts.
 */
final class SandboxVirtualBankReceiptAmount
{
    public static function resolve(ExchangeLot $lot): ?float
    {
        $fromType = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'from');
        $toType = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'to');
        if ($fromType === null || $toType === null) {
            return null;
        }

        $amountTo = (float) ($lot->buyer_selected_amount ?? 0);
        $rate = (float) $lot->rate;
        if ($amountTo > 0 && $rate > 0) {
            $amountFrom = $amountTo / $rate;
            if ($fromType === 'fiat') {
                return $amountFrom;
            }
            if ($toType === 'fiat') {
                return $amountTo;
            }
        }

        $sellAmount = (float) ($lot->sell_amount ?? 0);
        if ($sellAmount <= 0) {
            return null;
        }
        if ($fromType === 'fiat') {
            return $sellAmount;
        }
        if ($toType === 'fiat' && $rate > 0) {
            return $sellAmount * $rate;
        }

        return null;
    }

    public static function fiatCurrencyCode(ExchangeLot $lot): string
    {
        $lot->loadMissing(['fromFiatCurrency', 'toFiatCurrency']);
        if ($lot->fromFiatCurrency) {
            return (string) $lot->fromFiatCurrency->code;
        }
        if ($lot->toFiatCurrency) {
            return (string) $lot->toFiatCurrency->code;
        }

        return 'FIAT';
    }
}

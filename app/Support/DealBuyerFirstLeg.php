<?php

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * Buyer first payment leg (bank vs on-chain crypto). Mirrors xboard-client ExchangeLot.kt.
 *
 * @see docs/DEAL_IN_PROGRESS_SCREENS.md §4.1 (client doc; same rules on API)
 */
final class DealBuyerFirstLeg
{
    public static function buyerFirstLegIsBankTransfer(ExchangeLot $lot): ?bool
    {
        $fromType = self::currencyTypeForLotSide($lot, 'from');
        $toType = self::currencyTypeForLotSide($lot, 'to');
        if ($fromType === null || $toType === null) {
            return null;
        }
        $fromOnChain = in_array($fromType, ['crypto', 'stablecoin'], true);

        return match (true) {
            $fromType === 'fiat' => true,
            // crypto→fiat: buyer pays on-chain crypto to seller token; seller pays fiat second leg.
            $fromOnChain && $toType === 'fiat' => false,
            $fromOnChain && $toType === 'stablecoin' => false,
            $fromOnChain && $toType === 'crypto' => false,
            default => null,
        };
    }

    public static function buyerFirstLegPaysCrypto(ExchangeLot $lot): ?bool
    {
        $bank = self::buyerFirstLegIsBankTransfer($lot);
        if ($bank === null) {
            return null;
        }

        return ! $bank;
    }

    public static function currencyTypeForLotSide(ExchangeLot $lot, string $side): ?string
    {
        if ($side === 'from') {
            if ($lot->from_fiat_currency_id) {
                return 'fiat';
            }
            if ($lot->from_crypto_currency_id) {
                $lot->loadMissing('fromCryptoCurrency');
                $row = $lot->fromCryptoCurrency;

                return $row && ! $row->is_native ? 'stablecoin' : 'crypto';
            }
            // Legacy rows: seller crypto in crypto_currency_id only (XMR→USD and similar).
            if ($lot->to_fiat_currency_id && ! $lot->from_fiat_currency_id && $lot->crypto_currency_id) {
                $lot->loadMissing('cryptoCurrency');
                $row = $lot->cryptoCurrency;

                return $row && ! $row->is_native ? 'stablecoin' : 'crypto';
            }

            return null;
        }
        if ($side === 'to') {
            if ($lot->to_fiat_currency_id) {
                return 'fiat';
            }
            if ($lot->to_crypto_currency_id) {
                $lot->loadMissing('toCryptoCurrency');
                $row = $lot->toCryptoCurrency;

                return $row && ! $row->is_native ? 'stablecoin' : 'crypto';
            }

            return null;
        }

        return null;
    }
}

<?php

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * Seller Core reservation amount in escrow-token units (not buyer to_currency fiat).
 *
 * Mirrors xboard-client {@see ExchangeLot::sellerReservedAmountForRequirements} in Kotlin.
 */
final class SellerEscrowReservedAmount
{
    public static function escrowCoreTypeMatchesLotCurrencyField(string $tokenCore, string $lotCurrency): bool
    {
        $token = strtolower(trim($tokenCore));
        $code = strtolower(trim($lotCurrency));
        if ($token === '' || $code === '') {
            return false;
        }
        if ($token === $code) {
            return true;
        }
        if (str_starts_with($token, $code.'_')) {
            return true;
        }
        $tokenBase = explode('_', $token, 2)[0];

        return ! str_contains($code, '_') && $tokenBase !== '' && $tokenBase === $code;
    }

    /**
     * @return float|null Amount in units of {@see $escrowTokenCoreType}, or null when token does not match lot legs.
     */
    public static function resolve(ExchangeLot $lot, string $escrowTokenCoreType): ?float
    {
        $amountTo = (float) ($lot->buyer_selected_amount ?? 0);
        if ($amountTo <= 0) {
            return null;
        }
        $rate = (float) $lot->rate;
        if ($rate <= 0) {
            return null;
        }
        $amountFrom = $amountTo / $rate;
        $token = strtolower(trim($escrowTokenCoreType));
        if ($token === '') {
            return null;
        }

        $lot->loadMissing(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);

        $fromCurrency = strtolower(trim($lot->from_currency));
        $toCurrency = strtolower(trim($lot->to_currency));
        $toCryptoCode = $lot->cryptoCurrency
            ? strtolower(trim((string) $lot->cryptoCurrency->code))
            : null;

        $matchesFrom = self::escrowCoreTypeMatchesLotCurrencyField($token, $fromCurrency);
        $matchesTo = self::escrowCoreTypeMatchesLotCurrencyField($token, $toCurrency);
        $matchesToCrypto = $toCryptoCode !== null
            && $toCryptoCode !== ''
            && self::escrowCoreTypeMatchesLotCurrencyField($token, $toCryptoCode);

        if (! $matchesFrom && ! $matchesTo && ! $matchesToCrypto) {
            return null;
        }

        $fromType = self::currencyTypeForLotSide($lot, 'from');
        $toType = self::currencyTypeForLotSide($lot, 'to');

        if ($fromType === 'fiat' && in_array($toType, ['crypto', 'stablecoin'], true) && ($matchesTo || $matchesToCrypto)) {
            return $amountTo;
        }
        if (in_array($fromType, ['crypto', 'stablecoin'], true) && $matchesFrom) {
            return $amountFrom;
        }
        if ($matchesFrom) {
            return $amountFrom;
        }
        if ($matchesTo || $matchesToCrypto) {
            return $amountTo;
        }

        return null;
    }

    private static function currencyTypeForLotSide(ExchangeLot $lot, string $side): ?string
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

            return null;
        }
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
}

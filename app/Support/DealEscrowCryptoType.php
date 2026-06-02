<?php

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * Core crypto_type for seller vs buyer escrow legs on a deal lot.
 */
final class DealEscrowCryptoType
{
    public static function sellerCoreType(ExchangeLot $lot): string
    {
        $row = self::sellerEscrowCryptoCurrency($lot);
        if ($row === null) {
            throw new \InvalidArgumentException('Lot has no crypto currency for escrow (from_crypto, to_crypto, or crypto_currency_id).');
        }

        return self::coreTypeFromCurrencyRow($row);
    }

    public static function buyerCoreType(ExchangeLot $lot): string
    {
        $row = self::buyerEscrowCryptoCurrency($lot);
        if ($row === null) {
            throw new \InvalidArgumentException('Lot has no crypto currency for buyer escrow (to_crypto, from_crypto, or crypto_currency_id).');
        }

        return self::coreTypeFromCurrencyRow($row);
    }

    private static function sellerEscrowCryptoCurrency(ExchangeLot $lot): ?object
    {
        $lot->loadMissing(['fromCryptoCurrency', 'toCryptoCurrency', 'cryptoCurrency']);
        if ($lot->fromCryptoCurrency) {
            return $lot->fromCryptoCurrency;
        }
        if ($lot->toCryptoCurrency) {
            return $lot->toCryptoCurrency;
        }

        return $lot->cryptoCurrency;
    }

    private static function buyerEscrowCryptoCurrency(ExchangeLot $lot): ?object
    {
        $lot->loadMissing(['fromCryptoCurrency', 'toCryptoCurrency', 'cryptoCurrency']);
        if ($lot->toCryptoCurrency) {
            return $lot->toCryptoCurrency;
        }
        if ($lot->fromCryptoCurrency) {
            return $lot->fromCryptoCurrency;
        }

        return $lot->cryptoCurrency;
    }

    private static function coreTypeFromCurrencyRow(object $row): string
    {
        $code = strtolower(trim((string) ($row->code ?? '')));
        if ($code === '') {
            throw new \InvalidArgumentException('Linked crypto currency has empty code.');
        }
        if (preg_match('/_(stellar|polygon|tron)$/', $code)) {
            return $code;
        }
        $network = strtolower(trim((string) ($row->network ?? '')));
        if ($network !== '' && $network !== 'native' && ! str_contains($code, '_')) {
            return $code.'_'.$network;
        }

        return $code;
    }
}

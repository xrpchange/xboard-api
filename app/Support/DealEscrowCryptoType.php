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

    public static function coreNetworkFromCryptoType(string $cryptoType): ?string
    {
        if (preg_match('/^(usdt|usdc|dai)_(stellar|polygon|tron)$/', strtolower(trim($cryptoType)), $m)) {
            return $m[2];
        }

        return null;
    }

    public static function fromEscrowTokenJson(string $escrowTokenJson): ?string
    {
        $decoded = json_decode($escrowTokenJson, true);
        if (! is_array($decoded) || ! isset($decoded['currency'])) {
            return null;
        }
        $raw = trim((string) $decoded['currency']);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(USDT|USDC|DAI)_(STELLAR|POLYGON|TRON)$/i', $raw, $m)) {
            return strtolower($m[1].'_'.$m[2]);
        }
        $currencyUpper = strtoupper($raw);
        $walletAddress = isset($decoded['wallet_address']) && is_string($decoded['wallet_address'])
            ? trim($decoded['wallet_address'])
            : '';
        $inferredNet = DealPayoutDestinationAddressValidation::inferStablecoinNetworkFromBlockchainAddress($walletAddress);
        $network = $inferredNet ?? (isset($decoded['network']) ? strtolower(trim((string) $decoded['network'])) : '');
        if ($network !== '' && in_array($network, ['stellar', 'tron', 'polygon'], true)) {
            foreach (['USDT' => 'usdt', 'USDC' => 'usdc', 'DAI' => 'dai'] as $sym => $prefix) {
                if ($currencyUpper === $sym) {
                    return $prefix.'_'.$network;
                }
            }
        }

        return match ($currencyUpper) {
            'XRP' => 'xrp',
            'BTC' => 'btc',
            'XMR' => 'xmr',
            'MATIC', 'POLYGON' => 'polygon',
            default => null,
        };
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

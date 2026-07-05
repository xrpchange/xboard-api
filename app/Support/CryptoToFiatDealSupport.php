<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * Mixed pair where buyer pays on-chain crypto first and receives fiat on the second leg.
 * Buyer may deposit from any external wallet to the seller escrow token address only;
 * inbound amount is reserved on the seller token until deal completion or refund.
 */
final class CryptoToFiatDealSupport
{
    /**
     * Currency pair before lot row exists (store/update validation).
     * Seller escrow token is chosen at deal step 1, not at lot create.
     */
    public static function isCryptoToFiatCurrencyPair(
        ?object $fromFiat,
        ?object $fromCrypto,
        ?object $toFiat,
        ?object $toCrypto,
    ): bool {
        if ($fromFiat !== null || $toCrypto !== null) {
            return false;
        }

        return $fromCrypto !== null && $toFiat !== null;
    }

    public static function isCryptoToFiatDeal(ExchangeLot $lot): bool
    {
        if (! $lot->to_fiat_currency_id || $lot->from_fiat_currency_id) {
            return false;
        }
        if ($lot->from_crypto_currency_id || ($lot->crypto_currency_id && ! $lot->to_crypto_currency_id)) {
            $from = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'from');
            $to = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'to');

            return in_array($from, ['crypto', 'stablecoin'], true) && $to === 'fiat';
        }

        return false;
    }

    /**
     * Monero (XMR) crypto→fiat: buyer pays from any external wallet; at start_deal only
     * configured buy_amounts tiers are checked — not seller remaining crypto volume.
     * Other crypto→fiat pairs (e.g. USDT→USD with AML) keep the remaining-volume tier gate.
     */
    public static function isMoneroCryptoToFiatDeal(ExchangeLot $lot): bool
    {
        if (! self::isCryptoToFiatDeal($lot)) {
            return false;
        }

        return self::lotFromCryptoBaseCode($lot) === 'xmr';
    }

    public static function skipsStartDealRemainingVolumeTierCheck(ExchangeLot $lot): bool
    {
        return self::isMoneroCryptoToFiatDeal($lot);
    }

    private static function lotFromCryptoBaseCode(ExchangeLot $lot): ?string
    {
        if ($lot->from_crypto_currency_id) {
            $lot->loadMissing('fromCryptoCurrency');
            $code = $lot->fromCryptoCurrency?->code;
            if (is_string($code) && trim($code) !== '') {
                return self::normalizeCryptoBaseCode($code);
            }
        }
        if ($lot->crypto_currency_id) {
            $lot->loadMissing('cryptoCurrency');
            $code = $lot->cryptoCurrency?->code;
            if (is_string($code) && trim($code) !== '') {
                return self::normalizeCryptoBaseCode($code);
            }
        }
        $fromCurrency = trim((string) ($lot->from_currency ?? ''));
        if ($fromCurrency !== '') {
            return self::normalizeCryptoBaseCode($fromCurrency);
        }

        return null;
    }

    private static function normalizeCryptoBaseCode(string $code): string
    {
        $normalized = strtolower(trim($code));

        return explode('_', $normalized, 2)[0];
    }
}

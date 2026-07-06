<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * Mixed pair where buyer pays on-chain crypto first and receives fiat on the second leg.
 * Seller sells fiat (either from=fiat+to=crypto or from=crypto+to=fiat in lot fields).
 * Buyer may deposit from any external wallet to the seller escrow token address only;
 * inbound amount is reserved on the seller token after verified buyer_crypto_tx.
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
        if ($fromFiat !== null && $toFiat !== null) {
            return false;
        }
        if ($fromCrypto !== null && $toCrypto !== null) {
            return false;
        }

        return ($fromCrypto !== null && $toFiat !== null)
            || ($fromFiat !== null && $toCrypto !== null);
    }

    public static function isCryptoToFiatDeal(ExchangeLot $lot): bool
    {
        $from = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'from');
        $to = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'to');
        if ($from === null || $to === null) {
            return false;
        }
        $fromOnChain = in_array($from, ['crypto', 'stablecoin'], true);
        $toOnChain = in_array($to, ['crypto', 'stablecoin'], true);

        return ($fromOnChain && $to === 'fiat') || ($from === 'fiat' && $toOnChain);
    }

    /**
     * Monero (XMR) on buyer first leg: buyer pays from any external wallet; at start_deal only
     * configured buy_amounts tiers are checked — not seller remaining crypto volume.
     * Other mixed pairs (e.g. USDT→USD with AML) keep the remaining-volume tier gate.
     */
    public static function isMoneroCryptoToFiatDeal(ExchangeLot $lot): bool
    {
        if (! self::isCryptoToFiatDeal($lot)) {
            return false;
        }

        return self::lotBuyerFirstLegCryptoBaseCode($lot) === 'xmr';
    }

    public static function skipsStartDealRemainingVolumeTierCheck(ExchangeLot $lot): bool
    {
        return self::isMoneroCryptoToFiatDeal($lot);
    }

    /**
     * Buyer pays on-chain crypto first (from=crypto/stablecoin, to=fiat).
     */
    public static function buyerCryptoFirstLegCryptoToFiat(ExchangeLot $lot): bool
    {
        $from = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'from');
        $to = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'to');

        return in_array($from, ['crypto', 'stablecoin'], true) && $to === 'fiat';
    }

    /**
     * Buyer pays on-chain crypto first (from=crypto/stablecoin, to=fiat): inbound reservation
     * (service_fee_only + inbound_deposit_lock) is on the seller escrow token at payment_details,
     * not on the buyer wallet token sent with deal_completed_buyer.
     */
    public static function sellerEscrowPublicJsonForReservationRelease(ExchangeLot $lot): ?string
    {
        if (! self::buyerCryptoFirstLegCryptoToFiat($lot)) {
            return null;
        }
        $wallet = trim((string) ($lot->wallet_address ?? ''));
        if ($wallet === '' || ! str_starts_with($wallet, '{')) {
            return null;
        }

        return $wallet;
    }

    private static function lotBuyerFirstLegCryptoBaseCode(ExchangeLot $lot): ?string
    {
        $from = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'from');
        $to = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'to');
        if ($from === null || $to === null) {
            return null;
        }
        $fromOnChain = in_array($from, ['crypto', 'stablecoin'], true);
        $toOnChain = in_array($to, ['crypto', 'stablecoin'], true);
        if ($fromOnChain && $to === 'fiat') {
            $code = self::normalizeCryptoBaseCode((string) ($lot->from_currency ?? ''));
            if ($code !== '') {
                return $code;
            }

            return self::lotFromCryptoBaseCode($lot);
        }
        if ($from === 'fiat' && $toOnChain) {
            $code = self::normalizeCryptoBaseCode((string) ($lot->to_currency ?? ''));
            if ($code !== '') {
                return $code;
            }

            return self::lotFromCryptoBaseCode($lot);
        }
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
        if ($lot->to_crypto_currency_id) {
            $lot->loadMissing('toCryptoCurrency');
            $code = $lot->toCryptoCurrency?->code;
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

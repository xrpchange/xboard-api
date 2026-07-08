<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * AML lots on non-crypto→fiat paths may still require escrow-token server payout.
 * crypto→fiat: buyer deposits from any wallet to seller token; manual buyer_crypto_tx + verify-external-payment.
 */
final class EscrowTokenOnlyCryptoDealRules
{
    public static function isMoneroCoreType(string $cryptoType): bool
    {
        $code = strtolower(trim($cryptoType));

        return in_array($code, ['xmr', 'monero'], true);
    }

    public static function buyerFirstLegRequiresEscrowTokenServerPayout(ExchangeLot $lot): bool
    {
        if (CryptoToFiatDealSupport::isCryptoToFiatDeal($lot)) {
            return false;
        }

        if (! (bool) $lot->aml) {
            return false;
        }

        if (DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot) !== true) {
            return false;
        }

        $buyerCoreType = self::buyerFirstLegCoreType($lot);
        if ($buyerCoreType !== null && self::isMoneroCoreType($buyerCoreType)) {
            $sellerCoreType = self::sellerEscrowCoreType($lot);
            $cryptoToCryptoMoneroCollision =
                self::isCryptoToCryptoDeal($lot) &&
                $sellerCoreType !== null &&
                ! self::isMoneroCoreType($sellerCoreType);

            return $cryptoToCryptoMoneroCollision;
        }

        return true;
    }

    public static function sellerSecondLegRequiresEscrowTokenOnly(ExchangeLot $lot): bool
    {
        $firstLegBank = DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot);
        if (DealBuyerSecondLeg::buyerReceivesCrypto($lot, $firstLegBank) !== true) {
            return false;
        }
        try {
            return self::isMoneroCoreType(DealEscrowCryptoType::sellerCoreType($lot));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function legRequiresEscrowTokenOnly(ExchangeLot $lot, string $tagType): bool
    {
        return match (trim($tagType)) {
            'buyer_crypto_tx' => self::buyerFirstLegRequiresEscrowTokenServerPayout($lot),
            'seller_transfer_tx' => self::sellerSecondLegRequiresEscrowTokenOnly($lot),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $dealTagData
     * @return array{message:string,error_code:string,status:int}|null
     */
    public static function rejectManualCryptoProofIfRequired(ExchangeLot $lot, array $dealTagData): ?array
    {
        $tagType = trim((string) ($dealTagData['t'] ?? ''));
        if (! in_array($tagType, ['buyer_crypto_tx', 'seller_transfer_tx'], true)) {
            return null;
        }
        if (filter_var($dealTagData['via_escrow_server'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }
        if (! self::legRequiresEscrowTokenOnly($lot, $tagType)) {
            return null;
        }

        return [
            'message' => 'This deal requires a server payout from your escrow token. Manual transaction id proof is not accepted.',
            'error_code' => 'manual_crypto_tx_requires_escrow_payout',
            'status' => 422,
        ];
    }

    public static function shouldVerifyViaEscrowRecords(ExchangeLot $lot, array $dealTagData, string $cryptoType, string $tagType): bool
    {
        if ($tagType === 'buyer_crypto_tx' && CryptoToFiatDealSupport::isCryptoToFiatDeal($lot)) {
            return false;
        }
        if (self::isMoneroCoreType($cryptoType) && $tagType === 'seller_transfer_tx') {
            return true;
        }
        if (filter_var($dealTagData['via_escrow_server'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return self::legRequiresEscrowTokenOnly($lot, $tagType);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildEscrowPayoutCoreTxCheck(
        string $cryptoType,
        string $txHash,
        ?float $expectedAmount,
        ?string $destinationAddress,
        string $payoutRole,
    ): array {
        $observed = array_filter([
            'transaction_id' => $txHash,
            'amount' => $expectedAmount,
            'to_address' => $destinationAddress,
        ], static fn ($value) => $value !== null && $value !== '');

        $expected = array_filter([
            'amount' => $expectedAmount,
            'address' => $destinationAddress,
        ], static fn ($value) => $value !== null && $value !== '');

        return [
            'source' => 'escrow_token_payout',
            'checked_at_utc' => now()->utc()->toIso8601String(),
            'verified' => true,
            'status' => 'escrow_payout_confirmed',
            'is_confirmed' => true,
            'confirmations' => null,
            'checks' => [
                'confirmations_ok' => null,
                'address_matches' => null,
                'amount_matches' => true,
                'escrow_token_payout' => true,
            ],
            'observed' => $observed,
            'expected' => $expected,
            'crypto_type' => $cryptoType,
            'transaction_id' => $txHash,
            'payout_role' => $payoutRole,
        ];
    }

    private static function buyerFirstLegCoreType(ExchangeLot $lot): ?string
    {
        try {
            return DealEscrowCryptoType::buyerCoreType($lot);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function sellerEscrowCoreType(ExchangeLot $lot): ?string
    {
        try {
            return DealEscrowCryptoType::sellerCoreType($lot);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function isCryptoToCryptoDeal(ExchangeLot $lot): bool
    {
        $from = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'from');
        $to = DealBuyerFirstLeg::currencyTypeForLotSide($lot, 'to');
        if ($from === null || $to === null) {
            return false;
        }

        return in_array($from, ['crypto', 'stablecoin'], true)
            && in_array($to, ['crypto', 'stablecoin'], true);
    }
}

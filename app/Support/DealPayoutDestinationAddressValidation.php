<?php

namespace App\Support;

/**
 * Validates external blockchain addresses for deal crypto payouts (buyer destination, compensation).
 */
final class DealPayoutDestinationAddressValidation
{
    /**
     * @return array{message: string, error_code: string}|null
     */
    public static function validate(string $address, string $cryptoType): ?array
    {
        $dest = trim($address);
        if ($dest === '') {
            return [
                'message' => 'Crypto destination address must not be empty.',
                'error_code' => 'crypto_address_invalid',
            ];
        }
        if (strlen($dest) > 500) {
            return [
                'message' => 'Crypto destination address is too long.',
                'error_code' => 'crypto_address_invalid',
            ];
        }

        $stablecoinError = self::validateStablecoinNetwork($dest, $cryptoType);
        if ($stablecoinError !== null) {
            return $stablecoinError;
        }

        $coreType = strtolower(trim($cryptoType));
        if (preg_match('/^(usdt|usdc|dai)_(stellar|polygon|tron)$/', $coreType)) {
            return null;
        }

        return self::validateNativeCryptoAddress($dest, $coreType);
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    private static function validateStablecoinNetwork(string $address, string $cryptoType): ?array
    {
        $payoutNetwork = DealEscrowCryptoType::coreNetworkFromCryptoType($cryptoType);
        if ($payoutNetwork === null) {
            return null;
        }
        $destNet = self::inferStablecoinNetworkFromBlockchainAddress($address);
        $hint = match ($payoutNetwork) {
            'stellar' => 'Stellar account (starts with G, 56 characters)',
            'tron' => 'Tron address (starts with T)',
            'polygon' => 'Polygon address (0x + 40 hex)',
            default => $payoutNetwork,
        };
        if ($destNet === null) {
            return [
                'message' => 'Invalid payout address for this deal. Expected '.$hint.'.',
                'error_code' => 'crypto_address_invalid',
            ];
        }
        if ($destNet !== $payoutNetwork) {
            return [
                'message' => 'Payout address network ('.$destNet.') does not match deal payment asset ('.$payoutNetwork.'). Expected '.$hint.'.',
                'error_code' => 'crypto_address_network_mismatch',
            ];
        }

        return null;
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    private static function validateNativeCryptoAddress(string $address, string $coreType): ?array
    {
        if ($coreType === 'xmr') {
            if (! preg_match('/^[48][1-9A-HJ-NP-Za-km-z]{94,105}$/', $address)) {
                return [
                    'message' => 'Invalid Monero address. Expected a standard or subaddress (starts with 4 or 8).',
                    'error_code' => 'crypto_address_invalid',
                ];
            }

            return null;
        }
        if ($coreType === 'xrp') {
            if (! preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/', $address)) {
                return [
                    'message' => 'Invalid XRP address. Expected a classic address starting with r.',
                    'error_code' => 'crypto_address_invalid',
                ];
            }

            return null;
        }
        if ($coreType === 'btc') {
            if (! preg_match('/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,90}$/', $address)) {
                return [
                    'message' => 'Invalid Bitcoin address format.',
                    'error_code' => 'crypto_address_invalid',
                ];
            }

            return null;
        }

        return null;
    }

    public static function inferStablecoinNetworkFromBlockchainAddress(string $address): ?string
    {
        $w = trim($address);
        if ($w === '') {
            return null;
        }
        if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $w)) {
            return 'tron';
        }
        if (preg_match('/^G[A-Z2-7]{55}$/', $w)) {
            return 'stellar';
        }
        if (preg_match('/^0x[a-fA-F0-9]{40}$/', $w)) {
            return 'polygon';
        }

        return null;
    }
}

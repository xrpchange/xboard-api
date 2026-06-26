<?php

namespace App\Support;

/**
 * Parse escrow public JSON (Core-style crypto_type). Shared by start_deal validation and payout flows.
 */
final class DealEscrowPublicPart
{
    public static function coreCryptoTypeFromJson(string $escrowTokenJson): ?string
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
        $inferredNet = self::inferStablecoinNetworkFromBlockchainAddress($walletAddress);
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

    public static function coreTypesCompatible(?string $tokenType, string $lotType): bool
    {
        $token = strtolower(trim((string) $tokenType));
        $lot = strtolower(trim($lotType));
        if ($token === '' || $lot === '') {
            return false;
        }
        if ($token === $lot) {
            return true;
        }
        if (str_starts_with($token, $lot.'_') || str_starts_with($lot, $token.'_')) {
            return true;
        }
        $tokenBase = str_contains($token, '_') ? strstr($token, '_', true) : $token;
        $lotBase = str_contains($lot, '_') ? strstr($lot, '_', true) : $lot;

        return $tokenBase !== false && $lotBase !== false && $tokenBase === $lotBase;
    }

    public static function tokenHashFromJson(string $escrowTokenJson): ?string
    {
        $decoded = json_decode($escrowTokenJson, true);
        if (! is_array($decoded)) {
            return null;
        }
        $hash = trim((string) ($decoded['token_hash'] ?? ''));

        return $hash !== '' ? strtolower($hash) : null;
    }

    /** On-chain receive address from escrow public JSON (Stellar G…, Tron T…, etc.). */
    public static function blockchainReceiveAddressFromJson(string $escrowTokenJson): ?string
    {
        $decoded = json_decode($escrowTokenJson, true);
        if (! is_array($decoded)) {
            return null;
        }
        $wa = $decoded['wallet_address'] ?? null;
        if (! is_string($wa)) {
            return null;
        }
        $trimmed = trim($wa);

        return $trimmed !== '' ? $trimmed : null;
    }

    private static function inferStablecoinNetworkFromBlockchainAddress(string $address): ?string
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

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalize client export / nested escrow JSON for Core API and lot wallet_address.
 */
final class TrainingDealEscrowTokenFormat
{
    public static function normalizeForCore(string $json): string
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return $json;
        }

        if (isset($decoded['token']) && is_array($decoded['token'])) {
            $decoded = $decoded['token'];
        }

        if (isset($decoded['public_part']) && is_array($decoded['public_part'])) {
            $merged = $decoded['public_part'];
            if (! empty($decoded['encrypted_part']) && is_string($decoded['encrypted_part'])) {
                $merged['encrypted_part'] = $decoded['encrypted_part'];
            }

            return json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function publicPartJson(string $json): ?string
    {
        $normalized = self::normalizeForCore($json);
        $decoded = json_decode($normalized, true);
        if (! is_array($decoded)) {
            return null;
        }
        unset($decoded['encrypted_part'], $decoded['encrypted_data']);

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed>|null */
    public static function decoded(string $json): ?array
    {
        $normalized = self::normalizeForCore($json);
        $decoded = json_decode($normalized, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function encryptedData(string $json): ?string
    {
        $decoded = self::decoded($json);
        if ($decoded === null) {
            return null;
        }
        $enc = trim((string) ($decoded['encrypted_part'] ?? $decoded['encrypted_data'] ?? ''));

        return $enc !== '' ? $enc : null;
    }

    public static function tokenHash(string $json): ?string
    {
        $decoded = self::decoded($json);
        if ($decoded === null) {
            return null;
        }
        $hash = strtolower(trim((string) ($decoded['token_hash'] ?? '')));

        return $hash !== '' ? $hash : null;
    }

    /**
     * @return array{crypto_type: string, network: string}|null
     */
    public static function coreUploadParams(string $json): ?array
    {
        $decoded = self::decoded($json);
        if ($decoded === null) {
            return null;
        }
        $currency = strtolower(trim((string) ($decoded['currency'] ?? 'usdt')));
        $cryptoType = $currency === 'usdt' ? 'usdt' : $currency;
        $network = strtolower(trim((string) ($decoded['network'] ?? '')));
        if ($network === '') {
            return null;
        }

        return ['crypto_type' => $cryptoType, 'network' => $network];
    }
}

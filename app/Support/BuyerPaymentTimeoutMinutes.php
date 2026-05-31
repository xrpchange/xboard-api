<?php

namespace App\Support;

/**
 * Per-step deal timer options (minutes) set on exchange lots at create/update.
 */
final class BuyerPaymentTimeoutMinutes
{
    /** @var list<int> */
    public const ALLOWED = [7, 10, 15];

    public const DEFAULT = 15;

    public static function validationRule(): string
    {
        return 'nullable|integer|in:'.implode(',', self::ALLOWED);
    }

    public static function normalize(?int $minutes): int
    {
        return in_array($minutes, self::ALLOWED, true) ? $minutes : self::DEFAULT;
    }

    /**
     * Effective timeout for an existing lot (legacy 20/30 rows keep their stored value).
     */
    public static function effectiveForLot(?int $stored): int
    {
        if ($stored === null || $stored < 1) {
            return self::DEFAULT;
        }

        return $stored;
    }
}

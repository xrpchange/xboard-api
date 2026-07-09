<?php

declare(strict_types=1);

namespace App\Support;

final class MoneroUnlockedBalanceError
{
    public static function matches(string $message): bool
    {
        $normalized = strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'insufficient unlocked balance')
            || str_contains($normalized, 'not enough unlocked money')
            || str_contains($normalized, 'wallet-rpc authoritative');
    }
}

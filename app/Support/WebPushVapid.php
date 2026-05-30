<?php

declare(strict_types=1);

namespace App\Support;

final class WebPushVapid
{
    public static function configured(): bool
    {
        $public = trim((string) config('web_push.vapid.public_key', ''));
        $private = trim((string) config('web_push.vapid.private_key', ''));

        return $public !== '' && $private !== '';
    }

    public static function publicKey(): string
    {
        return trim((string) config('web_push.vapid.public_key', ''));
    }
}

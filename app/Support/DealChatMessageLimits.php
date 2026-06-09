<?php

namespace App\Support;

final class DealChatMessageLimits
{
    public static function maxBodyLength(): int
    {
        return max(1, (int) config('deal.max_message_length', 1000));
    }

    public static function messageValidationRule(bool $required = true): string
    {
        $max = self::maxBodyLength();

        return ($required ? 'required' : 'nullable').'|string|max:'.$max;
    }
}

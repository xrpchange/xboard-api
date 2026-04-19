<?php

namespace App\Support;

/**
 * Browser-facing URLs in email and operator web flows must use https (mixed content / form warnings on http).
 */
final class PublicHttpsUrl
{
    public static function upgradeMailBaseToHttps(string $base): string
    {
        $b = rtrim($base, '/');
        if ($b === '') {
            return $b;
        }
        if (preg_match('#\Ahttps://#i', $b)) {
            return $b;
        }
        if (preg_match('#\Ahttp://#i', $b)) {
            return 'https://'.preg_replace('#\Ahttp://#i', '', $b);
        }
        if (! str_contains($b, '://')) {
            return 'https://'.$b;
        }

        return $b;
    }
}

<?php

namespace App\Support;

/**
 * Browser-facing URLs in email should use https (mixed content / security warnings on http).
 * Local http is preserved for localhost / 127.0.0.1 development.
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
        if (preg_match('#\Ahttp://(localhost|127\.0\.0\.1)(:\d+)?(\z|/)#i', $b)) {
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

<?php

namespace App\Support;

/**
 * Deep link base for opening a deal in the Android app (intent-filter xboard://deal/open).
 * Not configurable: scheme/path are fixed by the client; host/query come from the lot.
 */
final class DealSellerOpenLinks
{
    public const APP_DEEP_LINK_BASE = 'xboard://deal/open';

    public const ANDROID_PACKAGE = 'com.xdexboard.xboard';

    /**
     * @param  array<string, string|int>  $params  lot_id, node_key
     */
    public static function appDeepLink(array $params): string
    {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $base = rtrim(self::APP_DEEP_LINK_BASE, '?&');
        if ($query === '') {
            return $base;
        }
        $sep = str_contains($base, '?') ? '&' : '?';

        return $base.$sep.$query;
    }

    /**
     * Optional intent:// for manual tap on the HTTPS landing page only — not for email buttons
     * (Gmail blocks or ignores intent:// in HTML; browser_fallback_url opens HTTPS inside the mail WebView).
     *
     * @param  array<string, string|int>  $params
     */
    public static function androidIntentUri(array $params): string
    {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $path = 'deal/open'.($query !== '' ? '?'.$query : '');

        return 'intent://'.$path.'#Intent;scheme=xboard;package='.self::ANDROID_PACKAGE.';end';
    }
}

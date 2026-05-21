<?php

namespace App\Support;

/**
 * HTTPS origin of the web client for deal email deep links.
 *
 * Pattern: https://{domain} (production web) and https://dev.{domain} (dev web), any project domain.
 * No sandbox.{domain} web — sandbox.api.{domain} maps to https://{domain}.
 * DEFAULT_PROJECT_DOMAIN is the stock build fallback only. See docs/WEB_CLIENT_ORIGINS.md.
 */
final class ClientWebOrigin
{
    public const DEFAULT_PROJECT_DOMAIN = 'xdexboard.com';

    public static function normalize(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 256) {
            return null;
        }
        $upgraded = PublicHttpsUrl::upgradeMailBaseToHttps(rtrim($raw, '/'));
        $parts = parse_url($upgraded);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        if ($host === '' || (! str_contains($host, '.') && $host !== 'localhost')) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            return null;
        }
        if (! empty($parts['query']) || ! empty($parts['fragment'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return 'https://'.strtolower((string) $parts['host']).$port;
    }

    /**
     * Fallback when the client omits client_web_origin.
     * dev.api.{domain} → dev web; sandbox.api.{domain} and api.{domain} → production web (no sandbox web host).
     */
    public static function deriveFromApiHost(?string $apiBase): ?string
    {
        if (! is_string($apiBase) || trim($apiBase) === '') {
            return null;
        }
        $upgraded = PublicHttpsUrl::upgradeMailBaseToHttps(rtrim($apiBase, '/'));
        $host = parse_url($upgraded, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        if (preg_match('/^dev\.api\.(.+)$/', $host, $m)) {
            return self::normalize('https://dev.'.$m[1]);
        }
        if (preg_match('/^sandbox\.api\.(.+)$/', $host, $m)) {
            return self::normalize('https://'.$m[1]);
        }
        if (preg_match('/^api\.(.+)$/', $host, $m)) {
            return self::normalize('https://'.$m[1]);
        }

        return null;
    }

    /** Client-sent origin, else browser `Origin` header, else derived from the API request host. */
    public static function resolve(?string $fromClient, ?string $apiRequestBase = null, ?string $originHeader = null): ?string
    {
        $normalized = self::normalize($fromClient);
        if ($normalized !== null) {
            return $normalized;
        }
        $fromHeader = self::normalize($originHeader);
        if ($fromHeader !== null) {
            return $fromHeader;
        }

        return self::deriveFromApiHost($apiRequestBase);
    }
}

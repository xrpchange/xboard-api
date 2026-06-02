<?php

namespace App\Support;

/**
 * HTTPS origin of the web client for deal email deep links.
 *
 * Pattern: https://{domain} (production web) and https://dev.{domain} (dev web) where still used.
 * No sandbox.{domain} web — sandbox.api.{domain} maps to https://{domain}.
 * Domains in DOMAINS_WITHOUT_DEV_WEB: dev.api.{domain} also maps to https://{domain} (no dev.{domain} web).
 * DEFAULT_PROJECT_DOMAIN is the stock build fallback only. See docs/WEB_CLIENT_ORIGINS.md.
 */
final class ClientWebOrigin
{
    public const DEFAULT_PROJECT_DOMAIN = 'xdexboard.com';

    /** Project domains that use apex web only (dev.{domain} web host retired). */
    private const DOMAINS_WITHOUT_DEV_WEB = ['xdexboard.com', 'xrpchange.site'];

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
     * dev.api.{domain} → dev web, or apex web when {domain} has no dev web tier;
     * sandbox.api.{domain} and api.{domain} → production web (no sandbox web host).
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
            $domain = $m[1];
            if (in_array($domain, self::DOMAINS_WITHOUT_DEV_WEB, true)) {
                return self::normalize('https://'.$domain);
            }

            return self::normalize('https://dev.'.$domain);
        }
        if (preg_match('/^sandbox\.api\.(.+)$/', $host, $m)) {
            return self::normalize('https://'.$m[1]);
        }
        if (preg_match('/^api\.(.+)$/', $host, $m)) {
            return self::normalize('https://'.$m[1]);
        }

        return null;
    }

    /**
     * Web client host for DB (`exchange_lots.client_web_origin`): apex or dev host only, no `https://`.
     * Accepts full origin (`https://xdexboard.com`) or bare domain (`xdexboard.com`).
     */
    public static function hostForStorage(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 256) {
            return null;
        }
        $fromOrigin = self::normalize($raw);
        if ($fromOrigin !== null) {
            $host = parse_url($fromOrigin, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? strtolower($host) : null;
        }
        $host = strtolower(rtrim($raw, '/'));
        if (str_contains($host, '://') || str_contains($host, '/')) {
            return null;
        }
        if ($host === '' || (! str_contains($host, '.') && $host !== 'localhost')) {
            return null;
        }

        return $host;
    }

    /** Build HTTPS origin from stored host (or legacy full origin in DB). */
    public static function httpsOriginFromStorage(?string $stored): ?string
    {
        if (! is_string($stored)) {
            return null;
        }
        $stored = trim($stored);
        if ($stored === '') {
            return null;
        }

        return self::normalize($stored) ?? self::normalize('https://'.$stored);
    }

    /** Client-sent value, else browser `Origin`, else API host — for email/push links (full HTTPS origin). */
    public static function resolve(?string $fromClient, ?string $apiRequestBase = null, ?string $originHeader = null): ?string
    {
        $normalized = self::httpsOriginFromStorage($fromClient) ?? self::normalize($fromClient);
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

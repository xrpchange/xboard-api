<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether this API deployment serves production or sandbox deals (host-based, same rule as EscrowTokenController).
 */
final class ApiDeploymentEnvironment
{
    public const PRODUCTION = 'production';

    public const SANDBOX = 'sandbox';

    public static function current(): string
    {
        if (! app()->runningInConsole()) {
            try {
                $host = request()->getHost();

                return self::resolveFromHost(is_string($host) ? $host : null);
            } catch (\Throwable) {
                // request() unavailable
            }
        }

        $appUrl = (string) config('app.url', '');
        $host = is_string($appUrl) && $appUrl !== '' ? parse_url($appUrl, PHP_URL_HOST) : null;

        return self::resolveFromHost(is_string($host) ? $host : null);
    }

    public static function resolveFromHost(?string $host): string
    {
        $h = strtolower(trim($host ?? ''));
        if ($h !== '' && preg_match('/^(sandbox\.|dev\.)/', $h)) {
            return self::SANDBOX;
        }

        return self::PRODUCTION;
    }

    public static function isProduction(?string $environment = null): bool
    {
        return ($environment ?? self::current()) === self::PRODUCTION;
    }

    public static function label(?string $environment = null): string
    {
        return self::isProduction($environment) ? 'Production' : 'Sandbox';
    }

    /**
     * @return array{url: string, key: string, timeout: int}
     */
    public static function coreHttpConfig(?string $environment = null): array
    {
        $production = self::isProduction($environment);

        return [
            'url' => rtrim((string) ($production
                ? config('services.core_production.url', config('services.core.url', ''))
                : config('services.core.url', '')), '/'),
            'key' => (string) ($production
                ? config('services.core_production.key', config('services.core.key', ''))
                : config('services.core.key', '')),
            'timeout' => (int) ($production
                ? config('services.core_production.timeout', config('services.core.timeout', 10))
                : config('services.core.timeout', 10)),
        ];
    }
}

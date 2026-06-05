<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Public HTTPS block-explorer links for operator arbitration mail (production vs sandbox templates).
 */
final class CryptoTxExplorerUrl
{
    public static function forTx(string $cryptoType, string $txId, ?string $environment = null): ?string
    {
        $tx = trim($txId);
        if ($tx === '') {
            return null;
        }

        $type = strtolower(trim($cryptoType));
        if ($type === '') {
            return null;
        }

        $env = $environment ?? ApiDeploymentEnvironment::current();
        $template = self::resolveTemplate($type, $env);
        if ($template === null) {
            return null;
        }

        $url = str_replace('{tx}', rawurlencode($tx), $template);
        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        return $url;
    }

    private static function resolveTemplate(string $cryptoType, string $environment): ?string
    {
        /** @var array<string, mixed> $root */
        $root = config('xboard.tx_explorer_url_templates', []);

        $bucket = ApiDeploymentEnvironment::isProduction($environment) ? 'production' : 'sandbox';
        if (isset($root[$bucket]) && is_array($root[$bucket])) {
            $tpl = $root[$bucket][$cryptoType] ?? null;
            if (is_string($tpl) && trim($tpl) !== '') {
                return trim($tpl);
            }
        }

        $flat = $root[$cryptoType] ?? null;
        if (is_string($flat) && trim($flat) !== '') {
            return trim($flat);
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PHP on Windows (XAMPP) often has OPENSSL_CONF pointing at a missing file under Apache
 * (SetEnv C:/xampp/apache/bin/openssl.cnf) → EC keys fail with "Unable to create the local key."
 * putenv() cannot override Apache-injected env on Windows; we also mirror a valid cnf there.
 */
final class OpenSslWindowsConfig
{
    /** @var list<string> */
    private const CANDIDATE_PATHS = [
        'C:/xampp/php/extras/ssl/openssl.cnf',
        'C:/xampp/apache/conf/openssl.cnf',
    ];

    private const APACHE_BIN_CNF = 'C:/xampp/apache/bin/openssl.cnf';

    public static function applyIfNeeded(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return self::resolveExistingPath();
        }

        $resolved = self::resolveWorkingPath();
        if ($resolved === null) {
            return null;
        }

        self::mirrorApacheBinConfig($resolved);
        self::publishConfigPath($resolved);

        return $resolved;
    }

    public static function resolvePath(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return self::resolveWorkingPath();
        }

        return self::resolveExistingPath();
    }

    public static function ecKeyWorks(): bool
    {
        $options = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ];
        $cnf = self::resolvePath();
        if ($cnf !== null) {
            $options['config'] = $cnf;
        }
        $key = @openssl_pkey_new($options);

        return $key !== false;
    }

    private static function resolveExistingPath(): ?string
    {
        $current = self::normalizePath(getenv('OPENSSL_CONF') ?: null);
        if ($current !== null && is_file($current)) {
            return $current;
        }

        return null;
    }

    private static function resolveWorkingPath(): ?string
    {
        $current = self::normalizePath(getenv('OPENSSL_CONF') ?: null);
        if ($current !== null && is_file($current)) {
            return $current;
        }

        foreach (self::CANDIDATE_PATHS as $path) {
            $path = self::normalizePath($path);
            if ($path !== null && is_file($path)) {
                return $path;
            }
        }

        $fromPhpBinary = self::normalizePath(
            dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf'
        );
        if ($fromPhpBinary !== null && is_file($fromPhpBinary)) {
            return $fromPhpBinary;
        }

        if (function_exists('env')) {
            foreach ([env('OPENSSL_CONF'), env('XAMPP_OPENSSL_CONF')] as $envPath) {
                $envPath = self::normalizePath(is_string($envPath) ? $envPath : null);
                if ($envPath !== null && is_file($envPath)) {
                    return $envPath;
                }
            }
        }

        return null;
    }

    private static function mirrorApacheBinConfig(string $source): void
    {
        $target = self::normalizePath(self::APACHE_BIN_CNF);
        if ($target === null || ! is_file($source)) {
            return;
        }
        if (is_file($target)) {
            return;
        }
        $dir = dirname($target);
        if (! is_dir($dir)) {
            return;
        }
        @copy($source, $target);
    }

    private static function publishConfigPath(string $path): void
    {
        putenv('OPENSSL_CONF='.$path);
        $_ENV['OPENSSL_CONF'] = $path;
        $_SERVER['OPENSSL_CONF'] = $path;
    }

    private static function normalizePath(?string $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }
        $path = trim(str_replace('\\', '/', $path));

        return $path !== '' ? $path : null;
    }
}

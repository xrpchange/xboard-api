<?php

/**
 * Windows XAMPP: Apache ignores OPENSSL_CONF for openssl_pkey_new(); minishlink/web-push must pass "config".
 * Idempotent — safe to run on every composer dump-autoload.
 */

declare(strict_types=1);

$file = dirname(__DIR__).'/vendor/minishlink/web-push/src/Encryption.php';
if (! is_file($file)) {
    fwrite(STDERR, "[patch-web-push] skip: {$file} not found\n");
    exit(0);
}

$contents = file_get_contents($file);
if ($contents === false) {
    fwrite(STDERR, "[patch-web-push] read failed\n");
    exit(1);
}

if (str_contains($contents, 'resolveOpenSslConfigPath')) {
    exit(0);
}

$old = <<<'PHP'
    private static function createLocalKeyObject(): array
    {
        $keyResource = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
PHP;

$new = <<<'PHP'
    private static function createLocalKeyObject(): array
    {
        $keyOptions = [
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];
        $opensslCnf = self::resolveOpenSslConfigPath();
        if ($opensslCnf !== null) {
            $keyOptions['config'] = $opensslCnf;
        }
        $keyResource = openssl_pkey_new($keyOptions);
PHP;

if (! str_contains($contents, $old)) {
    fwrite(STDERR, "[patch-web-push] pattern not found — check minishlink/web-push version\n");
    exit(1);
}

$contents = str_replace($old, $new, $contents);

$helper = <<<'PHP'

    /**
     * Explicit openssl.cnf path (Windows Apache ignores OPENSSL_CONF env for EC keys).
     */
    private static function resolveOpenSslConfigPath(): ?string
    {
        $candidates = [];
        $fromEnv = getenv('OPENSSL_CONF');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $candidates[] = $fromEnv;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = 'C:/xampp/php/extras/ssl/openssl.cnf';
            $candidates[] = 'C:/xampp/apache/bin/openssl.cnf';
        }
        foreach ($candidates as $path) {
            $path = str_replace('\\', '/', trim($path));
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }
PHP;

// Insert helper before closing brace of class (last closing brace in file).
$pos = strrpos($contents, "}\n");
if ($pos === false) {
    fwrite(STDERR, "[patch-web-push] could not locate class end\n");
    exit(1);
}

$contents = substr_replace($contents, $helper."\n}\n", $pos, strlen("}\n"));

if (file_put_contents($file, $contents) === false) {
    fwrite(STDERR, "[patch-web-push] write failed\n");
    exit(1);
}

fwrite(STDOUT, "[patch-web-push] patched Encryption.php\n");

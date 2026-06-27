#!/usr/bin/env php
<?php
/** Enable training deal using storage/app/training_seller_*.json (no export helper required). */
declare(strict_types=1);

$root = dirname(__DIR__);
$tokenPath = $root.'/storage/app/training_seller_token.json';
$publicPath = $root.'/storage/app/training_seller_public.json';
$corePath = $root.'/storage/app/training_seller_core_token.json';

foreach ([$tokenPath, $publicPath, $corePath] as $p) {
    if (! is_readable($p)) {
        fwrite(STDERR, "Missing {$p}\n");
        exit(1);
    }
}

$publicJson = trim((string) file_get_contents($publicPath));
$coreToken = trim((string) file_get_contents($corePath));

$envPath = $root.'/.env';
$env = file_get_contents($envPath);
if (! is_string($env)) {
    exit(1);
}

$set = static function (string $key, string $value) use (&$env): void {
    $line = $key.'='.$value;
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
    $env = preg_match($pattern, $env)
        ? (preg_replace($pattern, $line, $env) ?? $env)
        : rtrim($env)."\n".$line."\n";
};

$quote = static function (string $value): string {
    if ($value === '' || preg_match('/[\s#="\']/', $value)) {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    return $value;
};

$set('TRAINING_DEAL_ENABLED', 'true');
$set('TRAINING_SELLER_NOTIFICATION_EMAIL', 'training-seller@xdexboard.com');
$set('TRAINING_LOT_SELL_AMOUNT', '5');
$set('TRAINING_LOT_BUY_AMOUNTS', '5');
$set('TRAINING_LOT_NOTE_FIAT_CRYPTO', $quote('Для обучения: USD → USDT (Stellar)'));
$set('TRAINING_LOT_NOTE_CRYPTO_FIAT', $quote('Для обучения: USDT (Stellar) → USD'));
$set('TRAINING_SELLER_ESCROW_PUBLIC_JSON', $quote($publicJson));
$set('TRAINING_SELLER_ESCROW_TOKEN', $quote($coreToken));

file_put_contents($envPath, $env);
echo "Updated .env\n";

passthru('php artisan config:clear', $c1);
passthru('php artisan migrate --force', $c2);
passthru('php artisan training:sync-lots', $c3);
exit($c3 !== 0 ? $c3 : ($c2 !== 0 ? $c2 : $c1));

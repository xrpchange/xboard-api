<?php

/**
 * One minimal GigaChat chat/completions call using the same config as the arbiter.
 * Run from repo root: php temp/test_gigachat_one_completion.php
 *
 * Requires .env: ARBITER_GIGACHAT_AUTHORIZATION_KEY (and optional GigaChat URLs/model).
 * Does not touch disputes or exchange lots.
 */

declare(strict_types=1);

use App\Services\Arbiter\GigaChatArbiterProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$arbiter = config('services.arbiter', []);
$g = is_array($arbiter['gigachat'] ?? null) ? $arbiter['gigachat'] : [];

$credential = trim((string) ($g['oauth_credential'] ?? ''));
if ($credential === '') {
    fwrite(STDERR, "Missing ARBITER_GIGACHAT_AUTHORIZATION_KEY in .env / config.\n");

    exit(1);
}

$provider = new GigaChatArbiterProvider(
    oauthCredential: $credential,
    scope: (string) ($g['scope'] ?? 'GIGACHAT_API_PERS'),
    oauthUrl: (string) ($g['oauth_url'] ?? 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth'),
    apiBaseUrl: (string) ($g['api_base_url'] ?? 'https://gigachat.devices.sberbank.ru/api/v1'),
    model: (string) ($g['model'] ?? 'GigaChat'),
    timeoutSeconds: (int) ($g['timeout'] ?? 45),
    verifySsl: (bool) ($g['verify_ssl'] ?? true),
);

try {
    $out = $provider->generate([
        'system_prompt' => 'Reply with exactly one word: OK.',
        'user_prompt' => 'Ping.',
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'GigaChat call failed: '.$e->getMessage()."\n");
    if (str_contains($e->getMessage(), 'SSL certificate') || str_contains($e->getMessage(), 'cURL error 60')) {
        fwrite(STDERR, "Hint: set ARBITER_GIGACHAT_VERIFY_SSL=false in .env, or use APP_ENV other than production (default verify is production-only).\n");
    }

    exit(1);
}

echo json_encode(
    [
        'ok' => true,
        'provider' => $out['provider'] ?? null,
        'model' => $out['model'] ?? null,
        'raw_preview' => mb_substr(trim((string) ($out['raw_text'] ?? '')), 0, 120),
    ],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
)."\n";

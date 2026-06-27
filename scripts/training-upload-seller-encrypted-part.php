<?php
/**
 * Upload training seller encrypted_part to sandbox Core (one-time / before each payout cycle).
 *
 * Usage:
 *   php scripts/training-upload-seller-encrypted-part.php
 *   php scripts/training-upload-seller-encrypted-part.php /path/to/export.json
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\TrainingDealEscrowTokenFormat;

$path = $argv[1] ?? storage_path('app/training_seller_token.json');
if (! is_readable($path)) {
    fwrite(STDERR, "Token file not readable: {$path}\n");

    exit(1);
}

$raw = file_get_contents($path);
if (! is_string($raw) || trim($raw) === '') {
    fwrite(STDERR, "Empty token file\n");

    exit(1);
}

$normalized = TrainingDealEscrowTokenFormat::normalizeForCore($raw);
$decoded = json_decode($normalized, true);
if (! is_array($decoded)) {
    fwrite(STDERR, "Invalid token JSON\n");

    exit(1);
}

$tokenHash = strtolower(trim((string) ($decoded['token_hash'] ?? '')));
$encrypted = trim((string) ($decoded['encrypted_part'] ?? $decoded['encrypted_data'] ?? ''));
if ($tokenHash === '' || $encrypted === '') {
    fwrite(STDERR, "Missing token_hash or encrypted_part\n");

    exit(1);
}

$network = strtolower(trim((string) ($decoded['network'] ?? 'stellar')));
$currency = strtolower(trim((string) ($decoded['currency'] ?? 'usdt')));
$cryptoType = $currency === 'usdt' ? 'usdt' : $currency;

$coreBase = rtrim((string) env('CORE_SANDBOX_URL', 'https://sandbox.api.core.xdexboard.com'), '/');
$coreBase = preg_replace('#/api\.php$#', '', $coreBase) ?? $coreBase;
$uploadUrl = $coreBase.'/api/escrow-tokens/upload-encrypted-part';

$payload = [
    'crypto_type' => $cryptoType,
    'network' => $network,
    'token_hash' => $tokenHash,
    'encrypted_data' => $encrypted,
];

$ch = curl_init($uploadUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "POST {$uploadUrl}\n";
echo "HTTP {$code}\n";
echo $response."\n";

exit($code >= 200 && $code < 300 ? 0 : 1);

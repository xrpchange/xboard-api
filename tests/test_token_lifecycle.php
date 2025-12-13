<?php
/**
 * Test script for Bearer token lifecycle
 * Tests that tokens expire and are recreated, not extended
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\UserToken;
use App\Services\CollateralTokenService;

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Testing Bearer Token Lifecycle ===\n\n";

$service = app(CollateralTokenService::class);
$publicPart = '{"version":"1.0","wallet_address":"test_wallet_lifecycle","currency":"BTC","environment":"sandbox","node_domain":"xdexboard.com","token_hash":"test_hash_lifecycle"}';

echo "1. Creating first Bearer token...\n";
$token1 = $service->createBearerToken($publicPart, '127.0.0.1');
$token1Record = UserToken::where('token', $token1)->first();
echo "   Token ID: {$token1Record->id}\n";
echo "   Expires at: {$token1Record->expires_at->format('Y-m-d H:i:s')}\n";
$expiresAt1 = $token1Record->expires_at->copy();
echo "\n";

echo "2. Creating Bearer token again (should return same token if valid)...\n";
$token2 = $service->createBearerToken($publicPart, '127.0.0.1');
$token2Record = UserToken::where('token', $token2)->first();
echo "   Token ID: {$token2Record->id}\n";
echo "   Expires at: {$token2Record->expires_at->format('Y-m-d H:i:s')}\n";
echo "   Same token: " . ($token1 === $token2 ? "YES ✓" : "NO ✗") . "\n";
echo "   Expiration unchanged: " . ($token2Record->expires_at->eq($expiresAt1) ? "YES ✓" : "NO ✗") . "\n";
echo "\n";

echo "3. Validating token (should NOT extend expiration)...\n";
$validated = $service->validateBearerToken($token1);
$token1Record->refresh();
echo "   Token valid: " . ($validated ? "YES ✓" : "NO ✗") . "\n";
echo "   Expiration unchanged: " . ($token1Record->expires_at->eq($expiresAt1) ? "YES ✓" : "NO ✗") . "\n";
echo "\n";

echo "4. Manually expiring token and creating new one...\n";
$token1Record->update(['expires_at' => now()->subDay()]);
echo "   Old token expired\n";
$token3 = $service->createBearerToken($publicPart, '127.0.0.1');
$token3Record = UserToken::where('token', $token3)->first();
echo "   New token ID: {$token3Record->id}\n";
echo "   New token created: " . ($token3 !== $token1 ? "YES ✓" : "NO ✗") . "\n";
echo "   New expiration: {$token3Record->expires_at->format('Y-m-d H:i:s')}\n";
echo "\n";

echo "5. Testing refresh with expired token...\n";
$refreshResult = $service->refreshBearerToken($token1, '127.0.0.1');
echo "   Refresh result: " . ($refreshResult ? "SUCCESS ✓" : "FAILED ✗") . "\n";
if ($refreshResult) {
    $newTokenRecord = UserToken::where('token', $refreshResult)->first();
    echo "   New token ID: {$newTokenRecord->id}\n";
    echo "   Different from old: " . ($refreshResult !== $token1 ? "YES ✓" : "NO ✗") . "\n";
}

// Cleanup
UserToken::whereIn('id', [$token1Record->id, $token2Record->id, $token3Record->id])->delete();
if ($refreshResult) {
    UserToken::where('token', $refreshResult)->delete();
}

echo "\n=== Test Complete ===\n";


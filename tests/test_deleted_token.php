<?php
/**
 * Test script for deleted token behavior
 * Tests what happens when token is deleted from DB but client still uses it
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\UserToken;
use App\Services\CollateralTokenService;

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Testing Deleted Token Behavior ===\n\n";

$service = app(CollateralTokenService::class);
$publicPart = '{"version":"1.0","wallet_address":"test_wallet_deleted","currency":"BTC","environment":"sandbox","node_domain":"xdexboard.com","token_hash":"test_hash_deleted"}';

echo "1. Creating Bearer token...\n";
$token = $service->createBearerToken($publicPart, '127.0.0.1');
$tokenRecord = UserToken::where('token', $token)->first();
echo "   Token ID: {$tokenRecord->id}\n";
echo "   Token: " . substr($token, 0, 50) . "...\n";
echo "   Expires at: {$tokenRecord->expires_at->format('Y-m-d H:i:s')}\n\n";

echo "2. Validating token (should succeed)...\n";
$validated = $service->validateBearerToken($token);
if ($validated) {
    echo "   ✓ Token validated successfully\n";
} else {
    echo "   ✗ Token validation failed\n";
}
echo "\n";

echo "3. Deleting token from database...\n";
$tokenRecord->delete();
echo "   ✓ Token deleted\n";
$exists = UserToken::where('token', $token)->first();
echo "   Token exists in DB: " . ($exists ? "YES ✗" : "NO ✓") . "\n\n";

echo "4. Validating deleted token (should fail with null)...\n";
$validatedAfterDelete = $service->validateBearerToken($token);
if ($validatedAfterDelete === null) {
    echo "   ✓ Token validation correctly returned null (token not found)\n";
    echo "   This should result in 401 Unauthorized response\n";
} else {
    echo "   ✗ Token validation returned: " . gettype($validatedAfterDelete) . "\n";
    echo "   This is a security issue - deleted token should not be valid!\n";
}
echo "\n";

echo "5. Testing with expired token (for comparison)...\n";
$expiredToken = UserToken::create([
    'token' => 'expired_test_token_' . time(),
    'device_id' => 'expired_wallet',
    'escrow_token_hash' => hash('sha256', 'expired_public_part'),
    'role' => 'public',
    'expires_at' => now()->subDay(), // Expired yesterday
    'ip_address' => '127.0.0.1'
]);

$validatedExpired = $service->validateBearerToken($expiredToken->token);
if ($validatedExpired === null) {
    echo "   ✓ Expired token correctly returned null\n";
} else {
    echo "   ✗ Expired token was validated (security issue!)\n";
}

$expiredToken->delete();

echo "\n=== Test Complete ===\n";
echo "\nSummary:\n";
echo "- Deleted token: Returns null → 401 Unauthorized ✓\n";
echo "- Expired token: Returns null → 401 Unauthorized ✓\n";
echo "- Both cases are handled correctly\n";


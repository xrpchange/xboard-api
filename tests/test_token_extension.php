<?php
/**
 * Test script for Bearer token auto-extension
 * Tests that tokens are automatically extended when they expire within 7 days
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\UserToken;
use App\Services\CollateralTokenService;

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Testing Bearer Token Auto-Extension ===\n\n";

$service = app(CollateralTokenService::class);

// Create a test token that expires in 5 days
$testToken = UserToken::create([
    'token' => 'test_token_' . time(),
    'device_id' => 'test_wallet_address',
    'escrow_token_hash' => hash('sha256', 'test_public_part'),
    'role' => 'public',
    'expires_at' => now()->addDays(5), // Expires in 5 days
    'ip_address' => '127.0.0.1'
]);

echo "1. Created test token:\n";
echo "   Token ID: {$testToken->id}\n";
echo "   Expires at: {$testToken->expires_at->format('Y-m-d H:i:s')}\n";
echo "   Days until expiration: " . now()->diffInDays($testToken->expires_at, false) . "\n\n";

// Validate token (should auto-extend)
echo "2. Validating token (should auto-extend)...\n";
$validatedToken = $service->validateBearerToken($testToken->token);

if ($validatedToken) {
    $testToken->refresh(); // Reload from database
    echo "   ✓ Token validated successfully\n";
    echo "   New expires at: {$testToken->expires_at->format('Y-m-d H:i:s')}\n";
    echo "   Days until expiration: " . now()->diffInDays($testToken->expires_at, false) . "\n";
    
    if ($testToken->expires_at->gt(now()->addDays(20))) {
        echo "   ✓ Token was auto-extended (expires in more than 20 days)\n";
    } else {
        echo "   ✗ Token was NOT auto-extended (expires in less than 20 days)\n";
    }
} else {
    echo "   ✗ Token validation failed\n";
}

echo "\n3. Testing with token that expires in 10 days (should NOT auto-extend)...\n";
$testToken2 = UserToken::create([
    'token' => 'test_token2_' . time(),
    'device_id' => 'test_wallet_address2',
    'escrow_token_hash' => hash('sha256', 'test_public_part2'),
    'role' => 'public',
    'expires_at' => now()->addDays(10), // Expires in 10 days
    'ip_address' => '127.0.0.1'
]);

$originalExpiresAt = $testToken2->expires_at->copy();
$validatedToken2 = $service->validateBearerToken($testToken2->token);
$testToken2->refresh();

if ($testToken2->expires_at->eq($originalExpiresAt)) {
    echo "   ✓ Token was NOT auto-extended (correct - more than 7 days remaining)\n";
} else {
    echo "   ✗ Token was auto-extended (incorrect - should not extend)\n";
}

echo "\n4. Testing with expired token (should fail validation)...\n";
$expiredToken = UserToken::create([
    'token' => 'expired_token_' . time(),
    'device_id' => 'expired_wallet',
    'escrow_token_hash' => hash('sha256', 'expired_public_part'),
    'role' => 'public',
    'expires_at' => now()->subDay(), // Expired yesterday
    'ip_address' => '127.0.0.1'
]);

$validatedExpired = $service->validateBearerToken($expiredToken->token);
if (!$validatedExpired) {
    echo "   ✓ Expired token correctly rejected\n";
} else {
    echo "   ✗ Expired token was accepted (security issue!)\n";
}

// Cleanup
$testToken->delete();
$testToken2->delete();
$expiredToken->delete();

echo "\n=== Test Complete ===\n";


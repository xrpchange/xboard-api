<?php
/**
 * Test script for Bearer token creation
 * Tests the /auth/bearer-token endpoint with a sample escrow token
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\UserToken;

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Testing Bearer Token Creation ===\n\n";

// Sample publicPart (from a real escrow token)
$publicPart = '{"version":"1.0","wallet_address":"tb1q8wzzcvd2yra8fst2jjqnpskr0v3j3dj74chka5","currency":"BTC","environment":"sandbox","node_domain":"xdexboard.com","token_hash":"debee573f0f561850e5ba3a2a92e6ea11e4f8c8b5a3c2d1e0f9a8b7c6d5e4f3a2b1"}';

echo "1. Testing with publicPart:\n";
echo "   " . substr($publicPart, 0, 100) . "...\n";
echo "   Length: " . strlen($publicPart) . " bytes\n\n";

// Check current count in user_tokens table
$beforeCount = UserToken::count();
echo "2. Current user_tokens count: $beforeCount\n\n";

// Call service directly
echo "3. Calling CollateralTokenService directly...\n";
try {
    $service = app(\App\Services\CollateralTokenService::class);
    $ipAddress = '127.0.0.1';
    
    echo "   Creating Bearer token...\n";
    $bearerToken = $service->createBearerToken($publicPart, $ipAddress);
    
    echo "   ✓ Bearer token created successfully\n";
    echo "   Bearer token: " . substr($bearerToken, 0, 50) . "...\n";
    
    // Decrypt token to get data
    $tokenData = $service->decryptToken($publicPart);
    echo "   Wallet address: " . substr($tokenData['wallet_address'], 0, 30) . "...\n";
    echo "   Currency: " . $tokenData['currency'] . "\n";
    echo "   Environment: " . ($tokenData['environment'] ?? 'N/A') . "\n";
    
} catch (\Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Trace:\n" . substr($e->getTraceAsString(), 0, 500) . "\n";
}

echo "\n4. Checking database...\n";
$afterCount = UserToken::count();
echo "   User tokens count after: $afterCount\n";
echo "   Difference: " . ($afterCount - $beforeCount) . "\n\n";

if ($afterCount > $beforeCount) {
    echo "   ✓ New token(s) created in database\n";
    $newTokens = UserToken::orderBy('id', 'desc')->limit($afterCount - $beforeCount)->get();
    foreach ($newTokens as $token) {
        echo "   - Token ID: {$token->id}\n";
        echo "     Bearer token: " . substr($token->token, 0, 50) . "...\n";
        echo "     Device ID: " . substr($token->device_id, 0, 30) . "...\n";
        echo "     Escrow token hash: " . ($token->escrow_token_hash ? substr($token->escrow_token_hash, 0, 20) . "..." : "NULL") . "\n";
        echo "     Expires at: " . ($token->expires_at ? $token->expires_at->format('Y-m-d H:i:s') : 'NULL') . "\n";
        echo "     Created at: " . $token->created_at->format('Y-m-d H:i:s') . "\n";
    }
} else {
    echo "   ✗ No new tokens in database\n";
    echo "   Checking all tokens...\n";
    $allTokens = UserToken::orderBy('id', 'desc')->limit(10)->get();
    if ($allTokens->isEmpty()) {
        echo "   Database is empty - no tokens at all\n";
    } else {
        echo "   Found " . $allTokens->count() . " existing tokens:\n";
        foreach ($allTokens as $token) {
            echo "   - Token ID: {$token->id}, Device: " . substr($token->device_id, 0, 20) . "...\n";
        }
    }
}

echo "\n=== Test Complete ===\n";


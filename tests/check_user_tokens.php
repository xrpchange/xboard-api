<?php
/**
 * Check user_tokens table
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\UserToken;

echo "=== Checking user_tokens table ===\n\n";

$count = UserToken::count();
echo "Total tokens: $count\n\n";

if ($count > 0) {
    echo "Recent tokens:\n";
    $tokens = UserToken::orderBy('id', 'desc')->limit(10)->get();
    foreach ($tokens as $token) {
        echo "ID: {$token->id}\n";
        echo "  Bearer token: " . substr($token->token, 0, 50) . "...\n";
        echo "  Device ID: " . substr($token->device_id, 0, 30) . "...\n";
        echo "  Escrow token hash: " . ($token->escrow_token_hash ? substr($token->escrow_token_hash, 0, 30) . "..." : "NULL") . "\n";
        echo "  Role: {$token->role}\n";
        echo "  Expires at: " . ($token->expires_at ? $token->expires_at->format('Y-m-d H:i:s') : 'NULL') . "\n";
        echo "  Created at: " . $token->created_at->format('Y-m-d H:i:s') . "\n";
        echo "\n";
    }
} else {
    echo "Table is empty - no tokens found\n";
}

echo "=== Done ===\n";


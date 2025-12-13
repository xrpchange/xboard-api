<?php
/**
 * Test Core API directly to check if token balance endpoint works
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$coreUrl = 'https://sandbox.api.core.xdexboard.com/api.php';
$escrowToken = '{"version":"1.0","wallet_address":"tb1q7j5frmvm0hq527rtfgf8y83qvm9ms7m28ava0l","currency":"BTC","environment":"sandbox","node_domain":"xdexboard.com","token_hash":"01cd36b0299a236915e8a0500c53cabe0520a00b5ad784952231f36e6aca050a"}';

echo "=== Testing Core API ===\n\n";

// Test both paths
$paths = [
    '/escrow-tokens/balance',
    '/api/escrow-tokens/balance'
];

foreach ($paths as $path) {
    echo "Testing: $coreUrl$path\n";
    echo "Token: " . substr($escrowToken, 0, 50) . "...\n\n";

    $ch = curl_init($coreUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'crypto_type' => 'btc',
            'escrow_token' => $escrowToken
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    echo "HTTP Code: $httpCode\n";
    if ($curlError) {
        echo "cURL Error: $curlError\n";
    }
    echo "Response: " . substr($response, 0, 200) . "\n";

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['data']['total']) || isset($data['total'])) {
            echo "\n✓ Balance check successful\n";
            $total = $data['data']['total'] ?? $data['total'];
            echo "Total balance: $total BTC\n";
        } else {
            echo "\n⚠ Response format unexpected\n";
        }
    } else {
        echo "\n✗ Balance check failed\n";
    }
    echo "\n" . str_repeat("-", 50) . "\n\n";
}

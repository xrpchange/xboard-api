<?php
/**
 * Test script for Exchange Lots API
 * Tests filtering by currencies and Bearer token authentication
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Configuration
$apiUrl = 'https://sandbox.api.xdexboard.com/api/v1';
$escrowToken = $argv[1] ?? null;

if (!$escrowToken) {
    echo "Usage: php test_exchange_lots.php <escrow_token>\n";
    echo "Example: php test_exchange_lots.php '{\"version\":\"1.0\",\"wallet_address\":\"...\",...}'\n";
    exit(1);
}

function makeRequest($url, $method = 'GET', $headers = [], $body = null) {
    $ch = curl_init($url);
    
    $curlHeaders = [];
    foreach ($headers as $key => $value) {
        $curlHeaders[] = "$key: $value";
    }
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $curlHeaders[] = "Content-Type: application/json";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        return ['error' => "cURL Error: $curlError", 'http_code' => 0];
    }
    
    return [
        'http_code' => $httpCode,
        'body' => json_decode($response, true),
        'raw' => $response
    ];
}

echo "=== Exchange Lots API Test ===\n\n";

// Step 1: Get Bearer Token
echo "1. Getting Bearer Token...\n";
$bearerResponse = makeRequest(
    "$apiUrl/auth/bearer-token",
    'POST',
    [],
    ['escrow_token' => $escrowToken]
);

if ($bearerResponse['http_code'] !== 200) {
    echo "   ✗ Failed to get Bearer token\n";
    echo "   HTTP Code: {$bearerResponse['http_code']}\n";
    echo "   Response: " . json_encode($bearerResponse['body'], JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

$bearerToken = $bearerResponse['body']['data']['bearer_token'] ?? null;
if (!$bearerToken) {
    echo "   ✗ Bearer token not found in response\n";
    echo "   Response: " . json_encode($bearerResponse['body'], JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

echo "   ✓ Bearer token obtained: " . substr($bearerToken, 0, 20) . "...\n\n";

// Step 2: Get all exchange lots
echo "2. Getting all exchange lots...\n";
$allLotsResponse = makeRequest(
    "$apiUrl/exchange-lots",
    'GET',
    ['Authorization' => "Bearer $bearerToken"]
);

if ($allLotsResponse['http_code'] !== 200) {
    echo "   ✗ Failed to get exchange lots\n";
    echo "   HTTP Code: {$allLotsResponse['http_code']}\n";
    echo "   Response: " . json_encode($allLotsResponse['body'], JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

$allLots = $allLotsResponse['body']['data'] ?? [];
echo "   ✓ Found " . count($allLots) . " exchange lot(s)\n";

if (!empty($allLots)) {
    $firstLot = $allLots[0];
    echo "   Sample lot:\n";
    echo "     - ID: " . ($firstLot['id'] ?? 'N/A') . "\n";
    echo "     - From: " . ($firstLot['from_currency'] ?? 'N/A') . "\n";
    echo "     - To: " . ($firstLot['to_currency'] ?? 'N/A') . "\n";
    echo "     - Rate: " . ($firstLot['rate'] ?? 'N/A') . "\n";
    echo "     - Amount: " . ($firstLot['amount'] ?? 'N/A') . "\n";
    echo "     - Status: " . ($firstLot['status'] ?? 'N/A') . "\n";
}
echo "\n";

// Step 3: Test filtering by from_currency
if (!empty($allLots)) {
    $testFromCurrency = $firstLot['from_currency'] ?? null;
    if ($testFromCurrency) {
        echo "3. Testing filter by from_currency=$testFromCurrency...\n";
        $filteredResponse = makeRequest(
            "$apiUrl/exchange-lots?from_currency=$testFromCurrency",
            'GET',
            ['Authorization' => "Bearer $bearerToken"]
        );
        
        if ($filteredResponse['http_code'] === 200) {
            $filteredLots = $filteredResponse['body']['data'] ?? [];
            echo "   ✓ Found " . count($filteredLots) . " lot(s) with from_currency=$testFromCurrency\n";
            
            // Verify all lots match the filter
            $allMatch = true;
            foreach ($filteredLots as $lot) {
                if (($lot['from_currency'] ?? '') !== $testFromCurrency) {
                    $allMatch = false;
                    break;
                }
            }
            echo "   " . ($allMatch ? "✓" : "✗") . " All lots match filter\n";
        } else {
            echo "   ✗ Filter request failed\n";
            echo "   HTTP Code: {$filteredResponse['http_code']}\n";
        }
        echo "\n";
    }
}

// Step 4: Test filtering by to_currency
if (!empty($allLots)) {
    $testToCurrency = $firstLot['to_currency'] ?? null;
    if ($testToCurrency) {
        echo "4. Testing filter by to_currency=$testToCurrency...\n";
        $filteredResponse = makeRequest(
            "$apiUrl/exchange-lots?to_currency=$testToCurrency",
            'GET',
            ['Authorization' => "Bearer $bearerToken"]
        );
        
        if ($filteredResponse['http_code'] === 200) {
            $filteredLots = $filteredResponse['body']['data'] ?? [];
            echo "   ✓ Found " . count($filteredLots) . " lot(s) with to_currency=$testToCurrency\n";
            
            // Verify all lots match the filter
            $allMatch = true;
            foreach ($filteredLots as $lot) {
                if (($lot['to_currency'] ?? '') !== $testToCurrency) {
                    $allMatch = false;
                    break;
                }
            }
            echo "   " . ($allMatch ? "✓" : "✗") . " All lots match filter\n";
        } else {
            echo "   ✗ Filter request failed\n";
            echo "   HTTP Code: {$filteredResponse['http_code']}\n";
        }
        echo "\n";
    }
}

// Step 5: Test filtering by both currencies
if (!empty($allLots)) {
    $testFromCurrency = $firstLot['from_currency'] ?? null;
    $testToCurrency = $firstLot['to_currency'] ?? null;
    if ($testFromCurrency && $testToCurrency) {
        echo "5. Testing filter by from_currency=$testFromCurrency AND to_currency=$testToCurrency...\n";
        $filteredResponse = makeRequest(
            "$apiUrl/exchange-lots?from_currency=$testFromCurrency&to_currency=$testToCurrency",
            'GET',
            ['Authorization' => "Bearer $bearerToken"]
        );
        
        if ($filteredResponse['http_code'] === 200) {
            $filteredLots = $filteredResponse['body']['data'] ?? [];
            echo "   ✓ Found " . count($filteredLots) . " lot(s) matching both filters\n";
            
            // Verify all lots match both filters
            $allMatch = true;
            foreach ($filteredLots as $lot) {
                if (($lot['from_currency'] ?? '') !== $testFromCurrency ||
                    ($lot['to_currency'] ?? '') !== $testToCurrency) {
                    $allMatch = false;
                    break;
                }
            }
            echo "   " . ($allMatch ? "✓" : "✗") . " All lots match both filters\n";
        } else {
            echo "   ✗ Combined filter request failed\n";
            echo "   HTTP Code: {$filteredResponse['http_code']}\n";
        }
        echo "\n";
    }
}

// Step 6: Test with non-existent currency
echo "6. Testing filter with non-existent currency...\n";
$nonExistentResponse = makeRequest(
    "$apiUrl/exchange-lots?from_currency=XYZ999",
    'GET',
    ['Authorization' => "Bearer $bearerToken"]
);

if ($nonExistentResponse['http_code'] === 200) {
    $filteredLots = $nonExistentResponse['body']['data'] ?? [];
    echo "   ✓ Request successful, found " . count($filteredLots) . " lot(s)\n";
    echo "   " . (count($filteredLots) === 0 ? "✓" : "✗") . " Expected 0 lots for non-existent currency\n";
} else {
    echo "   ✗ Request failed\n";
    echo "   HTTP Code: {$nonExistentResponse['http_code']}\n";
}
echo "\n";

// Step 7: Test without Bearer token (should fail)
echo "7. Testing without Bearer token (should fail)...\n";
$noAuthResponse = makeRequest(
    "$apiUrl/exchange-lots",
    'GET'
);

if ($noAuthResponse['http_code'] === 401) {
    echo "   ✓ Correctly rejected request without authentication (401)\n";
} else {
    echo "   ✗ Expected 401, got {$noAuthResponse['http_code']}\n";
}
echo "\n";

echo "=== Test Complete ===\n";


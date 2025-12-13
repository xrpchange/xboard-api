<?php
/**
 * Test script for Bearer Token Management System
 * Tests all scenarios: get token, refresh token, validation, expiration handling
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$apiUrl = 'https://sandbox.api.xdexboard.com/api/v1';

// Test escrow token (use a real one from your tokens)
$escrowTokenInput = $argv[1] ?? null;

if (!$escrowTokenInput) {
    echo "Usage: php test_bearer_token_system.php <escrow_token>\n";
    echo "Example: php test_bearer_token_system.php '{\"version\":\"1.0\",\"wallet_address\":\"...\",...}'\n";
    echo "Or full token: php test_bearer_token_system.php '{\"token\":{\"public_part\":\"...\",...},...}'\n";
    exit(1);
}

// Extract public_part if full token is provided
$escrowToken = $escrowTokenInput;
$tokenData = json_decode($escrowTokenInput, true);
if ($tokenData && isset($tokenData['token']['public_part'])) {
    // Full token format - extract public_part
    $escrowToken = $tokenData['token']['public_part'];
    echo "Note: Extracted public_part from full token\n";
} elseif ($tokenData && isset($tokenData['public_part'])) {
    // Nested format
    $escrowToken = $tokenData['public_part'];
    echo "Note: Extracted public_part from nested token\n";
}

echo "=== Bearer Token System Test ===\n\n";

// Helper function for API calls
function apiCall($url, $method = 'GET', $data = null, $bearerToken = null) {
    $ch = curl_init($url);
    
    $headers = ['Content-Type: application/json'];
    if ($bearerToken) {
        $headers[] = "Authorization: Bearer $bearerToken";
    }
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    if ($data && ($method === 'POST' || $method === 'PUT')) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        throw new RuntimeException("cURL Error: $curlError");
    }
    
    $result = json_decode($response, true);
    
    return [
        'http_code' => $httpCode,
        'response' => $result,
        'raw' => $response
    ];
}

$bearerToken = null;
$testResults = [];

try {
    // Test 1: Get Bearer token from escrow token
    echo "Test 1: Get Bearer token from escrow token\n";
    echo "  Escrow token: " . substr($escrowToken, 0, 50) . "...\n";
    
    $result1 = apiCall(
        "$apiUrl/auth/bearer-token",
        'POST',
        ['escrow_token' => $escrowToken]
    );
    
    echo "  HTTP Code: {$result1['http_code']}\n";
    
    if ($result1['http_code'] === 200 && isset($result1['response']['data']['bearer_token'])) {
        $bearerToken = $result1['response']['data']['bearer_token'];
        echo "  ✓ Bearer token obtained: " . substr($bearerToken, 0, 20) . "...\n";
        $testResults['get_token'] = 'PASS';
        
        if (isset($result1['response']['data']['expires_at'])) {
            echo "  Expires at: {$result1['response']['data']['expires_at']}\n";
        }
    } else {
        echo "  ✗ Failed to get Bearer token\n";
        echo "  Response: " . $result1['raw'] . "\n";
        if (isset($result1['response']['message'])) {
            echo "  Error message: {$result1['response']['message']}\n";
        }
        $testResults['get_token'] = 'FAIL';
        echo "\n⚠ Note: This might be because:\n";
        echo "  1. Token is not registered in xboard-api\n";
        echo "  2. Core API is not accessible or returns error\n";
        echo "  3. Token format is incorrect\n";
        echo "\nContinuing with other tests...\n\n";
        // Don't exit, continue with other tests
    }
    
    echo "\n";
    
    // Test 2: Validate Bearer token
    echo "Test 2: Validate Bearer token\n";
    $result2 = apiCall(
        "$apiUrl/auth/validate",
        'GET',
        null,
        $bearerToken
    );
    
    echo "  HTTP Code: {$result2['http_code']}\n";
    
    if ($result2['http_code'] === 200 && isset($result2['response']['data']['valid'])) {
        $isValid = $result2['response']['data']['valid'];
        echo "  ✓ Token is " . ($isValid ? 'valid' : 'invalid') . "\n";
        $testResults['validate'] = $isValid ? 'PASS' : 'FAIL';
    } else {
        echo "  ✗ Validation failed\n";
        echo "  Response: " . substr($result2['raw'], 0, 200) . "\n";
        $testResults['validate'] = 'FAIL';
    }
    
    echo "\n";
    
    // Test 3: Refresh Bearer token
    echo "Test 3: Refresh Bearer token\n";
    $result3 = apiCall(
        "$apiUrl/auth/refresh-token",
        'POST',
        null,
        $bearerToken
    );
    
    echo "  HTTP Code: {$result3['http_code']}\n";
    
    if ($result3['http_code'] === 200 && isset($result3['response']['data']['bearer_token'])) {
        $newBearerToken = $result3['response']['data']['bearer_token'];
        echo "  ✓ Bearer token refreshed: " . substr($newBearerToken, 0, 20) . "...\n";
        
        if ($newBearerToken !== $bearerToken) {
            echo "  ✓ New token is different from old token\n";
        } else {
            echo "  ⚠ New token is same as old token (may be expected)\n";
        }
        
        if (isset($result3['response']['data']['expires_at'])) {
            echo "  New expires at: {$result3['response']['data']['expires_at']}\n";
        }
        
        $bearerToken = $newBearerToken; // Use new token for next tests
        $testResults['refresh'] = 'PASS';
    } else {
        echo "  ✗ Refresh failed\n";
        echo "  Response: " . substr($result3['raw'], 0, 200) . "\n";
        $testResults['refresh'] = 'FAIL';
    }
    
    echo "\n";
    
    // Test 4: Validate refreshed token
    echo "Test 4: Validate refreshed Bearer token\n";
    $result4 = apiCall(
        "$apiUrl/auth/validate",
        'GET',
        null,
        $bearerToken
    );
    
    echo "  HTTP Code: {$result4['http_code']}\n";
    
    if ($result4['http_code'] === 200 && isset($result4['response']['data']['valid'])) {
        $isValid = $result4['response']['data']['valid'];
        echo "  ✓ Refreshed token is " . ($isValid ? 'valid' : 'invalid') . "\n";
        $testResults['validate_refreshed'] = $isValid ? 'PASS' : 'FAIL';
    } else {
        echo "  ✗ Validation of refreshed token failed\n";
        echo "  Response: " . substr($result4['raw'], 0, 200) . "\n";
        $testResults['validate_refreshed'] = 'FAIL';
    }
    
    echo "\n";
    
    // Test 5: Use Bearer token for authenticated request (nodes endpoint)
    echo "Test 5: Use Bearer token for authenticated request\n";
    $result5 = apiCall(
        "$apiUrl/nodes",
        'GET',
        null,
        $bearerToken
    );
    
    echo "  HTTP Code: {$result5['http_code']}\n";
    
    if ($result5['http_code'] === 200) {
        $nodesCount = count($result5['response']['data'] ?? []);
        echo "  ✓ Authenticated request successful\n";
        echo "  Nodes returned: $nodesCount\n";
        $testResults['authenticated_request'] = 'PASS';
    } else {
        echo "  ✗ Authenticated request failed\n";
        echo "  Response: " . substr($result5['raw'], 0, 200) . "\n";
        $testResults['authenticated_request'] = 'FAIL';
    }
    
    echo "\n";
    
    // Test 6: Test with invalid token (should fail)
    echo "Test 6: Test with invalid Bearer token (should fail)\n";
    $result6 = apiCall(
        "$apiUrl/auth/validate",
        'GET',
        null,
        'invalid_token_12345'
    );
    
    echo "  HTTP Code: {$result6['http_code']}\n";
    
    if ($result6['http_code'] === 401) {
        echo "  ✓ Invalid token correctly rejected (401)\n";
        $testResults['invalid_token'] = 'PASS';
    } else {
        echo "  ⚠ Unexpected response for invalid token\n";
        echo "  Response: " . substr($result6['raw'], 0, 200) . "\n";
        $testResults['invalid_token'] = 'WARN';
    }
    
    echo "\n";
    
    // Test 7: Test refresh with invalid token (should fail)
    echo "Test 7: Test refresh with invalid Bearer token (should fail)\n";
    $result7 = apiCall(
        "$apiUrl/auth/refresh-token",
        'POST',
        null,
        'invalid_token_12345'
    );
    
    echo "  HTTP Code: {$result7['http_code']}\n";
    
    if ($result7['http_code'] === 401) {
        echo "  ✓ Invalid token refresh correctly rejected (401)\n";
        $testResults['refresh_invalid'] = 'PASS';
    } else {
        echo "  ⚠ Unexpected response for invalid token refresh\n";
        echo "  Response: " . substr($result7['raw'], 0, 200) . "\n";
        $testResults['refresh_invalid'] = 'WARN';
    }
    
    echo "\n";
    
    // Test 8: Test nodes endpoint with country filter
    echo "Test 8: Test nodes endpoint with country filter\n";
    $result8 = apiCall(
        "$apiUrl/nodes?country=USA",
        'GET',
        null,
        $bearerToken
    );
    
    echo "  HTTP Code: {$result8['http_code']}\n";
    
    if ($result8['http_code'] === 200) {
        $nodesCount = count($result8['response']['data'] ?? []);
        echo "  ✓ Country filter request successful\n";
        echo "  Nodes returned: $nodesCount\n";
        $testResults['country_filter'] = 'PASS';
    } else {
        echo "  ⚠ Country filter request failed or no nodes found\n";
        echo "  Response: " . substr($result8['raw'], 0, 200) . "\n";
        $testResults['country_filter'] = 'WARN';
    }
    
    echo "\n";
    
    // Summary
    echo "=== Test Summary ===\n";
    foreach ($testResults as $test => $result) {
        $status = $result === 'PASS' ? '✓' : ($result === 'FAIL' ? '✗' : '⚠');
        echo "$status $test: $result\n";
    }
    
    $passed = count(array_filter($testResults, fn($r) => $r === 'PASS'));
    $total = count($testResults);
    echo "\nPassed: $passed/$total\n";
    
    if ($passed === $total) {
        echo "\n✓ All tests passed!\n";
        exit(0);
    } else {
        echo "\n⚠ Some tests failed or had warnings\n";
        exit(1);
    }
    
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}


<?php

/**
 * Test script for Yandex Mail API (instead of IMAP)
 * 
 * Usage: php test_yandex_mail_api.php <access_token>
 * 
 * This script tests various Yandex API endpoints that might provide email access
 */

require __DIR__ . '/vendor/autoload.php';

$accessToken = $argv[1] ?? null;

if (!$accessToken) {
    echo "Usage: php test_yandex_mail_api.php <access_token>\n";
    exit(1);
}

echo "Testing Yandex Mail API endpoints\n";
echo "Token preview: " . substr($accessToken, 0, 20) . "...\n\n";

$httpClient = new \GuzzleHttp\Client([
    'timeout' => 10,
    'verify' => true,
]);

// Test 1: Get user info (baseline - should work)
echo "=== Test 1: Get user info (baseline) ===\n";
try {
    $response = $httpClient->get('https://login.yandex.ru/info', [
        'headers' => [
            'Authorization' => 'OAuth ' . $accessToken,
        ],
    ]);
    
    $userInfo = json_decode($response->getBody()->getContents(), true);
    $email = $userInfo['default_email'] ?? null;
    $login = $userInfo['login'] ?? null;
    
    echo "✓ Token is valid\n";
    echo "  Email: $email\n";
    echo "  Login: $login\n\n";
    
} catch (\Exception $e) {
    echo "✗ Test 1 FAILED: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 2: Try api.mail.yandex.net (might not exist)
echo "=== Test 2: api.mail.yandex.net/v1/messages ===\n";
try {
    $response = $httpClient->get('https://api.mail.yandex.net/v1/messages', [
        'headers' => [
            'Authorization' => 'OAuth ' . $accessToken,
        ],
        'query' => [
            'limit' => 10,
        ],
    ]);
    
    echo "✓ API endpoint exists!\n";
    echo "Response: " . $response->getBody()->getContents() . "\n\n";
    
} catch (\GuzzleHttp\Exception\ClientException $e) {
    $statusCode = $e->getResponse()->getStatusCode();
    $body = $e->getResponse()->getBody()->getContents();
    echo "✗ HTTP $statusCode\n";
    echo "Response: $body\n\n";
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 3: Try api.yandex.ru/mail
echo "=== Test 3: api.yandex.ru/mail/v1/messages ===\n";
try {
    $response = $httpClient->get('https://api.yandex.ru/mail/v1/messages', [
        'headers' => [
            'Authorization' => 'OAuth ' . $accessToken,
        ],
        'query' => [
            'limit' => 10,
        ],
    ]);
    
    echo "✓ API endpoint exists!\n";
    echo "Response: " . $response->getBody()->getContents() . "\n\n";
    
} catch (\GuzzleHttp\Exception\ClientException $e) {
    $statusCode = $e->getResponse()->getStatusCode();
    $body = $e->getResponse()->getBody()->getContents();
    echo "✗ HTTP $statusCode\n";
    echo "Response: $body\n\n";
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 4: Try mail.yandex.ru/api
echo "=== Test 4: mail.yandex.ru/api/v1/messages ===\n";
try {
    $response = $httpClient->get('https://mail.yandex.ru/api/v1/messages', [
        'headers' => [
            'Authorization' => 'OAuth ' . $accessToken,
        ],
        'query' => [
            'limit' => 10,
        ],
    ]);
    
    echo "✓ API endpoint exists!\n";
    echo "Response: " . $response->getBody()->getContents() . "\n\n";
    
} catch (\GuzzleHttp\Exception\ClientException $e) {
    $statusCode = $e->getResponse()->getStatusCode();
    $body = $e->getResponse()->getBody()->getContents();
    echo "✗ HTTP $statusCode\n";
    echo "Response: $body\n\n";
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 5: Try caldav.yandex.ru (Calendar API, but might have mail endpoints)
echo "=== Test 5: caldav.yandex.ru (Calendar API) ===\n";
try {
    $response = $httpClient->request('PROPFIND', 'https://caldav.yandex.ru', [
        'headers' => [
            'Authorization' => 'OAuth ' . $accessToken,
            'Depth' => '0',
        ],
    ]);
    
    echo "✓ CALDAV endpoint accessible\n";
    echo "Response: " . $response->getBody()->getContents() . "\n\n";
    
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 6: Try webdav.yandex.ru (Disk API, might have mail)
echo "=== Test 6: webdav.yandex.ru (Disk API) ===\n";
try {
    $response = $httpClient->request('PROPFIND', 'https://webdav.yandex.ru', [
        'headers' => [
            'Authorization' => 'OAuth ' . $accessToken,
            'Depth' => '0',
        ],
    ]);
    
    echo "✓ WEBDAV endpoint accessible\n";
    echo "Response: " . $response->getBody()->getContents() . "\n\n";
    
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 7: Try oauth.yandex.ru/token_info (token introspection)
echo "=== Test 7: oauth.yandex.ru/token_info (token introspection) ===\n";
try {
    $response = $httpClient->post('https://oauth.yandex.ru/token_info', [
        'form_params' => [
            'oauth_token' => $accessToken,
        ],
    ]);
    
    echo "✓ Token introspection works\n";
    echo "Response: " . $response->getBody()->getContents() . "\n\n";
    
} catch (\GuzzleHttp\Exception\ClientException $e) {
    $statusCode = $e->getResponse()->getStatusCode();
    $body = $e->getResponse()->getBody()->getContents();
    echo "✗ HTTP $statusCode\n";
    echo "Response: $body\n\n";
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 8: Try api.passport.yandex.ru (Passport API)
echo "=== Test 8: api.passport.yandex.ru (Passport API) ===\n";
try {
    $response = $httpClient->get('https://api.passport.yandex.ru', [
        'headers' => [
            'Authorization' => 'OAuth ' . $accessToken,
        ],
    ]);
    
    echo "✓ Passport API accessible\n";
    echo "Response: " . $response->getBody()->getContents() . "\n\n";
    
} catch (\GuzzleHttp\Exception\ClientException $e) {
    $statusCode = $e->getResponse()->getStatusCode();
    $body = $e->getResponse()->getBody()->getContents();
    echo "✗ HTTP $statusCode\n";
    echo "Response: $body\n\n";
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

echo "=== Summary ===\n";
echo "All API endpoint tests completed. Check results above.\n";
echo "\nIf no Mail API endpoints work, Yandex personal accounts may not provide REST API for mail access.\n";
echo "Alternative: Use IMAP (if OAuth works) or email forwarding methods.\n";


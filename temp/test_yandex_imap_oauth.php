<?php

/**
 * Test script for Yandex IMAP XOAUTH2 connection
 * 
 * Usage: php test_yandex_imap_oauth.php <access_token>
 * 
 * This script tests two approaches:
 * 1. Using webklex/php-imap library
 * 2. Manual XOAUTH2 connection via raw sockets
 */

require __DIR__ . '/vendor/autoload.php';

$accessToken = $argv[1] ?? null;
$email = $argv[2] ?? 'TehnoGrany@yandex.ru';

if (!$accessToken) {
    echo "Usage: php test_yandex_imap_oauth.php <access_token> [email]\n";
    exit(1);
}

echo "Testing Yandex IMAP XOAUTH2 connection\n";
echo "Email: $email\n";
echo "Token preview: " . substr($accessToken, 0, 20) . "...\n\n";

// Test 1: Using webklex/php-imap library
echo "=== Test 1: Using webklex/php-imap library ===\n";
try {
    $cm = new \Webklex\PHPIMAP\ClientManager();
    $client = $cm->make([
        'host' => 'imap.yandex.com', // Use imap.yandex.com instead of imap.yandex.ru
        'port' => 993,
        'encryption' => 'ssl',
        'validate_cert' => true,
        'protocol' => 'imap',
        'username' => $email,
        'password' => $accessToken,
        'authentication' => 'oauth',
    ]);
    
    echo "Connecting...\n";
    $client->connect();
    echo "✓ Connection successful!\n";
    
    $folder = $client->getFolder('INBOX');
    echo "✓ INBOX folder accessed\n";
    
    $messages = $folder->messages()->limit(5)->get();
    echo "✓ Retrieved " . count($messages) . " messages\n";
    
    foreach ($messages as $message) {
        echo "  - Subject: " . $message->getSubject() . "\n";
    }
    
    $client->disconnect();
    echo "\n✓ Test 1 PASSED\n\n";
    
} catch (\Exception $e) {
    echo "✗ Test 1 FAILED: " . $e->getMessage() . "\n";
    echo "  " . $e->getTraceAsString() . "\n\n";
}

// Test 2: Manual XOAUTH2 via raw sockets (with email)
echo "=== Test 2: Manual XOAUTH2 via raw sockets (with email) ===\n";
try {
    $host = 'imap.yandex.com'; // Use imap.yandex.com instead of imap.yandex.ru
    $port = 993;
    
    // Get login from email (part before @)
    $login = explode('@', $email)[0];
    
    // Create XOAUTH2 string with login instead of full email
    // IMPORTANT: Use chr(1) to create actual byte with code 1 (not string "\x01")
    // Grok-4 suggests using login@yandex.ru format
    $xoauthString = base64_encode("user=$login@yandex.ru" . chr(1) . "auth=Bearer $accessToken" . chr(1) . chr(1));
    
    echo "Connecting to $host:$port...\n";
    $socket = @fsockopen("ssl://$host", $port, $errno, $errstr, 30);
    
    if (!$socket) {
        throw new Exception("Failed to connect: $errstr ($errno)");
    }
    
    echo "✓ Socket connected\n";
    
    // Read greeting
    $greeting = fgets($socket);
    echo "Server greeting: " . trim($greeting) . "\n";
    
    // Send AUTHENTICATE XOAUTH2 command
    $command = "a1 AUTHENTICATE XOAUTH2 $xoauthString\r\n";
    echo "Sending: a1 AUTHENTICATE XOAUTH2 [base64 string]\n";
    fputs($socket, $command);
    
    // Read response
    $response = fgets($socket);
    echo "Server response: " . trim($response) . "\n";
    
    if (strpos($response, '+') === 0) {
        echo "✓ Got continuation request (expected for some servers)\n";
        // Some servers send continuation, respond with empty
        fputs($socket, "\r\n");
        $response = fgets($socket);
        echo "Final response: " . trim($response) . "\n";
    }
    
    if (strpos($response, 'OK') !== false || strpos($response, 'a1 OK') !== false) {
        echo "✓ Authentication successful!\n";
        
        // Try to SELECT INBOX
        fputs($socket, "a2 SELECT INBOX\r\n");
        $response = '';
        while (($line = fgets($socket)) !== false) {
            $response .= $line;
            if (strpos($line, 'a2 OK') !== false || strpos($line, 'a2 BAD') !== false) {
                break;
            }
        }
        echo "SELECT INBOX response: " . trim($response) . "\n";
        
        if (strpos($response, 'a2 OK') !== false) {
            echo "✓ INBOX selected successfully\n";
        }
        
        if (is_resource($socket)) {
            fclose($socket);
        }
        echo "\n✓ Test 2 PASSED\n\n";
    } else {
        if (is_resource($socket)) {
            fclose($socket);
        }
        throw new Exception("Authentication failed: " . trim($response));
    }
    
} catch (\Exception $e) {
    echo "✗ Test 2 FAILED: " . $e->getMessage() . "\n";
    if (isset($socket) && is_resource($socket)) {
        fclose($socket);
    }
    echo "\n";
}

// Test 3: Verify token with Yandex API and get detailed info
echo "=== Test 3: Verify token with Yandex API ===\n";
try {
    $httpClient = new \GuzzleHttp\Client();
    
    // Try /info endpoint
    echo "Requesting /info endpoint...\n";
    $response = $httpClient->get('https://login.yandex.ru/info', [
        'headers' => [
            'Authorization' => 'OAuth ' . $accessToken,
        ],
        'timeout' => 10,
    ]);
    
    $userInfo = json_decode($response->getBody()->getContents(), true);
    echo "✓ Token is valid\n";
    echo "  Email: " . ($userInfo['default_email'] ?? 'unknown') . "\n";
    echo "  Login: " . ($userInfo['login'] ?? 'unknown') . "\n";
    echo "  Full response: " . json_encode($userInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    // Try to get token info with scopes (if available)
    echo "\nTrying /token endpoint to get token info...\n";
    try {
        $tokenResponse = $httpClient->post('https://oauth.yandex.ru/token', [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => 'test', // This won't work, but let's see the error
            ],
            'headers' => [
                'Authorization' => 'OAuth ' . $accessToken,
            ],
            'timeout' => 10,
        ]);
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        // Expected to fail, but let's see if we get any info
        $errorBody = $e->getResponse()->getBody()->getContents();
        echo "  Token endpoint response: " . $errorBody . "\n";
    }
    
    // Try /info with format parameter
    echo "\nTrying /info?format=json...\n";
    try {
        $response2 = $httpClient->get('https://login.yandex.ru/info', [
            'headers' => [
                'Authorization' => 'OAuth ' . $accessToken,
            ],
            'query' => [
                'format' => 'json',
            ],
            'timeout' => 10,
        ]);
        $userInfo2 = json_decode($response2->getBody()->getContents(), true);
        echo "  Full response with format=json: " . json_encode($userInfo2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } catch (\Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n✓ Test 3 completed\n\n";
    
} catch (\Exception $e) {
    echo "✗ Test 3 FAILED: " . $e->getMessage() . "\n";
    if ($e->getResponse()) {
        echo "  Response body: " . $e->getResponse()->getBody()->getContents() . "\n";
    }
    echo "\n";
}

echo "=== Summary ===\n";
echo "Tests completed. Check results above.\n";


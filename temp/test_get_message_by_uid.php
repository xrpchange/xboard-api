<?php

/**
 * Test script for getting email message by UID using webklex/php-imap library
 * 
 * Usage: php test_get_message_by_uid.php <access_token> [email] [uid]
 * 
 * This script tests:
 * 1. Getting message list and extracting UID
 * 2. Getting message by UID using messages()->where('uid', $uid)
 * 3. Getting raw email body
 */

require __DIR__ . '/vendor/autoload.php';

$accessToken = $argv[1] ?? null;
$email = $argv[2] ?? 'TehnoGrany@yandex.ru';
$testUid = isset($argv[3]) ? (int)$argv[3] : null;

if (!$accessToken) {
    echo "Usage: php test_get_message_by_uid.php <access_token> [email] [uid]\n";
    echo "  If UID is not provided, will use UID from first message in inbox\n";
    exit(1);
}

echo "Testing getting email message by UID\n";
echo "Email: $email\n";
echo "Token preview: " . substr($accessToken, 0, 20) . "...\n";
if ($testUid) {
    echo "Test UID: $testUid\n";
}
echo "\n";

try {
    $cm = new \Webklex\PHPIMAP\ClientManager();
    $client = $cm->make([
        'host' => 'imap.yandex.com',
        'port' => 993,
        'encryption' => 'ssl',
        'validate_cert' => true,
        'protocol' => 'imap',
        'username' => $email,
        'password' => $accessToken,
        'authentication' => 'oauth',
    ]);
    
    echo "=== Step 1: Connecting to IMAP ===\n";
    $client->connect();
    echo "✓ Connection successful!\n\n";
    
    $folder = $client->getFolder('INBOX');
    echo "✓ INBOX folder accessed\n\n";
    
    // Step 2: Get list of messages to find a UID
    echo "=== Step 2: Getting message list ===\n";
    // Use all() first, then limit in PHP to avoid IMAP command syntax issues
    $allMessages = $folder->messages()->all()->get();
    $messages = $allMessages->take(5);
    echo "✓ Retrieved " . count($messages) . " messages (from " . count($allMessages) . " total)\n\n";
    
    if (count($messages) === 0) {
        echo "✗ No messages found in inbox. Cannot test.\n";
        $client->disconnect();
        exit(1);
    }
    
    // Display messages and their UIDs
    echo "Messages in inbox:\n";
    $uidToTest = $testUid;
    foreach ($messages as $index => $message) {
        $uid = $message->getUid();
        $subject = $message->getSubject() ?? '(no subject)';
        echo "  [$index] UID: $uid, Subject: $subject\n";
        
        // If no UID specified, use first message's UID
        if ($index === 0 && !$uidToTest) {
            $uidToTest = $uid;
        }
    }
    echo "\n";
    
    if (!$uidToTest) {
        echo "✗ No UID to test. Exiting.\n";
        $client->disconnect();
        exit(1);
    }
    
    echo "=== Step 3: Testing getMessage by UID = $uidToTest ===\n";
    
    // Test method 1: Using messages()->where('uid', $uid)
    echo "\n--- Method 1: messages()->where('uid', $uidToTest) ---\n";
    try {
        $foundMessages = $folder->messages()
            ->where('uid', $uidToTest)
            ->limit(1)
            ->get();
        
        if ($foundMessages->isEmpty()) {
            echo "✗ FAILED: No message found with UID=$uidToTest\n";
        } else {
            $foundMessage = $foundMessages->first();
            echo "✓ SUCCESS: Message found!\n";
            echo "  UID: " . $foundMessage->getUid() . "\n";
            echo "  Subject: " . ($foundMessage->getSubject() ?? '(no subject)') . "\n";
            echo "  From: " . (is_array($foundMessage->getFrom()) && !empty($foundMessage->getFrom()) 
                ? $foundMessage->getFrom()[0]->mail 
                : 'unknown') . "\n";
            
            // Try to get raw body
            echo "\n--- Testing getRawBody() ---\n";
            try {
                $rawBody = $foundMessage->getRawBody();
                if ($rawBody) {
                    $rawBodyLength = strlen($rawBody);
                    echo "✓ SUCCESS: Raw body retrieved (length: $rawBodyLength bytes)\n";
                    echo "  First 200 chars: " . substr($rawBody, 0, 200) . "...\n";
                } else {
                    echo "✗ FAILED: getRawBody() returned null or empty\n";
                }
            } catch (\Exception $e) {
                echo "✗ FAILED: getRawBody() threw exception: " . $e->getMessage() . "\n";
                echo "  Trace: " . $e->getTraceAsString() . "\n";
            }
        }
    } catch (\Exception $e) {
        echo "✗ FAILED: Exception when getting message by UID: " . $e->getMessage() . "\n";
        echo "  Trace: " . $e->getTraceAsString() . "\n";
    }
    
    // Test method 2: Try alternative methods if available
    echo "\n--- Method 2: Checking available methods on folder ---\n";
    $reflection = new \ReflectionClass($folder);
    $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
    $relevantMethods = array_filter($methods, function($method) {
        $name = $method->getName();
        return stripos($name, 'message') !== false || 
               stripos($name, 'get') !== false ||
               stripos($name, 'uid') !== false;
    });
    
    echo "Available methods related to messages/get/uid:\n";
    foreach ($relevantMethods as $method) {
        echo "  - " . $method->getName() . "()\n";
    }
    
    $client->disconnect();
    echo "\n✓ Test completed\n";
    
} catch (\Exception $e) {
    echo "✗ Test FAILED: " . $e->getMessage() . "\n";
    echo "  Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}


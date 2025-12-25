<?php

/**
 * Test script: Parse DKIM and headers from raw email
 * 
 * Usage: php temp/test_dkim_parsing.php
 * 
 * Automatically gets email, token, and UID from logs
 * 
 * This script:
 * 1. Connects to IMAP via OAuth
 * 2. Retrieves raw email by UID
 * 3. Tests different methods to get full raw email with headers
 * 4. Parses DKIM and From headers
 * 5. Shows what's actually in the email
 */

require __DIR__ . '/../vendor/autoload.php';

// Load Laravel application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ImapMonitorService;
use App\Http\Controllers\Api\PaymentVerificationController;

// Get everything from logs
echo "=== Getting data from logs ===\n";
$logFile = __DIR__ . '/../storage/logs/laravel.log';
$email = null;
$accessToken = null;
$uid = null;

if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
    $logContent = substr($logContent, -100000); // Last 100KB
    
    // Get email
    if (preg_match('/"email":"([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})"/', $logContent, $matches)) {
        $email = $matches[1];
        echo "Found email: $email\n";
    }
    
    // Get token (last one)
    if (preg_match_all('/"full_token":"([^"]+)"/', $logContent, $allMatches)) {
        $accessToken = end($allMatches[1]);
        echo "Found token: " . substr($accessToken, 0, 20) . "...\n";
    }
    
    // Get UID from getRawEmailByNum calls
    if (preg_match_all('/getRawEmailByNum\((\d+)\)/', $logContent, $uidMatches)) {
        $uid = end($uidMatches[1]);
        echo "Found UID: $uid\n";
    }
}

if (!$email || !$accessToken || !$uid) {
    echo "ERROR: Could not get all required data from logs\n";
    echo "  Email: " . ($email ?: 'NOT FOUND') . "\n";
    echo "  Token: " . ($accessToken ? 'FOUND' : 'NOT FOUND') . "\n";
    echo "  UID: " . ($uid ?: 'NOT FOUND') . "\n";
    exit(1);
}

echo "=== Test: DKIM Parsing ===\n";
echo "User email: $email\n";
echo "UID: $uid\n";
echo "Token preview: " . substr($accessToken, 0, 20) . "...\n\n";

$imapService = null;
try {
    // Step 1: Connect to IMAP
    echo "=== Step 1: Connecting to IMAP ===\n";
    $imapService = new ImapMonitorService();
    
    $connected = $imapService->connectWithOAuth($email, $accessToken, 'imap.yandex.com', 993);
    
    if (!$connected) {
        echo "ERROR: Failed to connect to IMAP server\n";
        exit(1);
    }
    
    echo "✓ Connected to IMAP server\n\n";
    
    // Step 2: Get raw email
    echo "=== Step 2: Retrieving raw email ===\n";
    $rawEmail = $imapService->getRawEmailByNum((int)$uid);
    
    if (!$rawEmail) {
        echo "ERROR: Could not retrieve raw email\n";
        if ($imapService) {
            $imapService->disconnect();
        }
        exit(1);
    }
    
    echo "✓ Retrieved raw email\n";
    echo "  Raw email length: " . strlen($rawEmail) . " bytes\n";
    echo "  First 200 chars: " . substr($rawEmail, 0, 200) . "\n\n";
    
    // Step 3: Check what we got
    echo "=== Step 3: Analyzing raw email ===\n";
    
    // Check if it starts with headers or body
    if (preg_match('/^[A-Za-z-]+:/', $rawEmail)) {
        echo "✓ Email starts with headers (good!)\n";
    } else {
        echo "✗ Email does NOT start with headers (starts with: " . substr($rawEmail, 0, 50) . ")\n";
    }
    
    // Check for DKIM
    $hasDkim = strpos($rawEmail, 'DKIM-Signature') !== false;
    echo "  Has DKIM-Signature: " . ($hasDkim ? "YES" : "NO") . "\n";
    
    // Check for From
    $hasFrom = strpos($rawEmail, 'From:') !== false;
    echo "  Has From header: " . ($hasFrom ? "YES" : "NO") . "\n";
    
    // Show first 1000 chars
    echo "\n  First 1000 characters:\n";
    echo "  " . str_replace("\n", "\n  ", substr($rawEmail, 0, 1000)) . "\n\n";
    
    // Step 4: Try to parse DKIM using controller method
    echo "=== Step 4: Parsing DKIM using controller method ===\n";
    
    $controller = new PaymentVerificationController();
    $reflection = new \ReflectionClass($controller);
    $dkimMethod = $reflection->getMethod('verifyDKIM');
    $dkimMethod->setAccessible(true);
    
    // Test with the raw email we got
    echo "  Testing with raw email (length: " . strlen($rawEmail) . ")\n";
    $dkimResult = $dkimMethod->invoke($controller, $rawEmail);
    
    echo "  Result:\n";
    echo "    valid: " . ($dkimResult['valid'] ? 'true' : 'false') . "\n";
    echo "    domain: " . ($dkimResult['domain'] ?? 'null') . "\n";
    echo "    error: " . ($dkimResult['error'] ?? 'null') . "\n";
    echo "    signature: " . ($dkimResult['signature'] ? substr($dkimResult['signature'], 0, 80) . '...' : 'null') . "\n";
    
    if ($dkimResult['valid']) {
        echo "✓ DKIM signature is VALID\n";
        echo "  Domain: " . ($dkimResult['domain'] ?? 'unknown') . "\n";
    } else {
        echo "✗ DKIM signature is INVALID or NOT FOUND\n";
        if ($dkimResult['error']) {
            echo "  Error: " . $dkimResult['error'] . "\n";
        }
    }
    
    echo "\n";
    
    // Step 5: Try to parse From
    echo "=== Step 5: Parsing From header ===\n";
    
    $extractMethod = $reflection->getMethod('extractSenderInfo');
    $extractMethod->setAccessible(true);
    
    $senderInfo = $extractMethod->invoke($controller, $rawEmail);
    
    echo "  Sender email: " . ($senderInfo['email'] ?? 'NOT FOUND') . "\n";
    echo "  Sender domain: " . ($senderInfo['domain'] ?? 'NOT FOUND') . "\n";
    echo "  From header: " . ($senderInfo['from'] ?? 'NOT FOUND') . "\n";
    
    echo "\n";
    
    // Step 6: Show all headers
    echo "=== Step 6: All headers found ===\n";
    $lines = explode("\n", $rawEmail);
    $inHeaders = true;
    $headerCount = 0;
    foreach ($lines as $line) {
        if (trim($line) === '') {
            if ($inHeaders) {
                $inHeaders = false;
                echo "  (end of headers, body starts)\n";
                break;
            }
        }
        if ($inHeaders && preg_match('/^([A-Za-z-]+):\s*(.+)$/', $line, $matches)) {
            $headerCount++;
            $headerName = $matches[1];
            $headerValue = substr($matches[2], 0, 80);
            echo "  $headerName: $headerValue...\n";
        }
    }
    echo "  Total headers found: $headerCount\n";
    
    // Disconnect from IMAP
    if ($imapService) {
        $imapService->disconnect();
    }
    
    echo "\n=== TEST COMPLETE ===\n";
    
} catch (\Exception $e) {
    if ($imapService) {
        try {
            $imapService->disconnect();
        } catch (\Exception $disconnectError) {
            // Ignore disconnect errors
        }
    }
    
    echo "ERROR: " . $e->getMessage() . "\n";
    if ($e->getPrevious()) {
        echo "  Previous: " . $e->getPrevious()->getMessage() . "\n";
    }
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}


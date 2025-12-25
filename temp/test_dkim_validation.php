<?php

/**
 * Test script for DKIM validation using PHPMailer DKIMValidator
 * Extracts email, token, and UID from laravel.log and tests full DKIM verification
 */

require __DIR__ . '/../vendor/autoload.php';

// Load Laravel application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use PHPMailer\DKIMValidator\Validator as DKIMValidator;
use PHPMailer\DKIMValidator\DKIMException;
use App\Services\ImapMonitorService;

// Extract parameters from command line or laravel.log
$email = $argv[1] ?? null;
$token = $argv[2] ?? null;
$uid = $argv[3] ?? null;

// If not provided, try to extract from laravel.log
if (!$email || !$token) {
    $logFile = __DIR__ . '/../storage/logs/laravel.log';
    if (file_exists($logFile)) {
        $logContent = file_get_contents($logFile);
        $logContent = substr($logContent, -100000); // Last 100KB
        
        // Extract email
        if (!$email && preg_match('/Attempting IMAP OAuth connection.*?"email":"([^"]+)"/', $logContent, $matches)) {
            $email = $matches[1];
            echo "Found email in logs: $email\n";
        }
        
        // Extract token (last one)
        if (!$token && preg_match_all('/"full_token":"([^"]+)"/', $logContent, $allMatches)) {
            $token = end($allMatches[1]);
            echo "Found token in logs (length: " . strlen($token) . ")\n";
        }
        
        // Extract UID from getRawEmailByNum logs
        if (!$uid && preg_match_all('/getRawEmailByNum\((\d+)\)/', $logContent, $uidMatches)) {
            $uid = end($uidMatches[1]);
            echo "Found UID in logs: $uid\n";
        }
    }
}

if (!$email || !$token) {
    die("Usage: php test_dkim_validation.php [email] [token] [uid]\n" .
        "Or ensure laravel.log contains IMAP connection info.\n");
}

// Connect to IMAP using ImapMonitorService
echo "\n=== Connecting to IMAP ===\n";
$imapService = new ImapMonitorService();

try {
    $connected = $imapService->connectWithOAuth($email, $token, 'imap.yandex.com', 993);
    
    if (!$connected) {
        die("Failed to connect to IMAP server\n");
    }
    
    echo "Connected successfully\n";
    
    // Get raw email by UID or first email
    if ($uid) {
        echo "\n=== Getting email by UID: $uid ===\n";
        $rawEmail = $imapService->getRawEmailByNum((int)$uid);
    } else {
        echo "\n=== Getting first email ===\n";
        $emails = $imapService->getEmailList(1, 0);
        if (empty($emails)) {
            die("No emails found\n");
        }
        $firstEmail = $emails[0];
        $rawEmail = $imapService->getRawEmailByNum($firstEmail['emailNum']);
    }
    
    if (!$rawEmail) {
        die("Could not retrieve raw email\n");
    }
    
    echo "Raw email retrieved (length: " . strlen($rawEmail) . " bytes)\n";
    
    // Check for DKIM-Signature
    $hasDkim = strpos($rawEmail, 'DKIM-Signature') !== false;
    echo "Has DKIM-Signature: " . ($hasDkim ? 'YES' : 'NO') . "\n";
    
    // Check if email starts with headers
    if (preg_match('/^[A-Za-z-]+:/', $rawEmail)) {
        echo "Email starts with headers (good!)\n";
    } else {
        echo "WARNING: Email does NOT start with headers\n";
        echo "First 100 chars: " . substr($rawEmail, 0, 100) . "\n";
    }
    
    if ($hasDkim) {
        // Extract DKIM-Signature header
        if (preg_match('/DKIM-Signature:([^\r\n]+(?:\r?\n[ \t]+[^\r\n]+)*)/i', $rawEmail, $matches)) {
            $dkimHeader = trim($matches[1]);
            echo "\nDKIM-Signature header (first 200 chars):\n" . substr($dkimHeader, 0, 200) . "\n";
            
            // Extract domain
            if (preg_match('/d=([^;\s]+)/', $dkimHeader, $domainMatch)) {
                $dkimDomain = trim($domainMatch[1]);
                echo "DKIM Domain: $dkimDomain\n";
            }
            
            // Extract selector
            if (preg_match('/s=([^;\s]+)/', $dkimHeader, $selectorMatch)) {
                $selector = trim($selectorMatch[1]);
                echo "DKIM Selector: $selector\n";
            }
        }
    }
    
    // Test DKIM validation
    echo "\n=== Testing DKIM Validation ===\n";
    
    try {
        $dkimValidator = new DKIMValidator($rawEmail);
        
        echo "DKIMValidator created successfully\n";
        
        // Try validate() for detailed info
        echo "\nCalling validate()...\n";
        $validationResult = $dkimValidator->validate();
        
        echo "Validation result:\n";
        print_r($validationResult);
        
        // Try validateBoolean() for simple check
        echo "\nCalling validateBoolean()...\n";
        $isValid = $dkimValidator->validateBoolean();
        echo "Is valid: " . ($isValid ? 'YES' : 'NO') . "\n";
        
        // Check DNS for public key
        if (isset($dkimDomain) && isset($selector)) {
            echo "\n=== Checking DNS for public key ===\n";
            $dnsRecord = $selector . '._domainkey.' . $dkimDomain;
            echo "DNS query: $dnsRecord\n";
            
            $dnsResult = dns_get_record($dnsRecord, DNS_TXT);
            if ($dnsResult) {
                echo "DNS TXT record found:\n";
                print_r($dnsResult);
            } else {
                echo "DNS TXT record NOT found!\n";
            }
        }
        
    } catch (DKIMException $e) {
        echo "DKIMException: " . $e->getMessage() . "\n";
        echo "Exception class: " . get_class($e) . "\n";
        if (method_exists($e, 'getTraceAsString')) {
            echo "Trace:\n" . $e->getTraceAsString() . "\n";
        }
    } catch (\Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
        echo "Exception class: " . get_class($e) . "\n";
        echo "Trace:\n" . $e->getTraceAsString() . "\n";
    }
    
    // Disconnect
    $imapService->disconnect();
    
} catch (\Exception $e) {
    if (isset($imapService)) {
        try {
            $imapService->disconnect();
        } catch (\Exception $disconnectError) {
            // Ignore disconnect errors
        }
    }
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== Test completed ===\n";


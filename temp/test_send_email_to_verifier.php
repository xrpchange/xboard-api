<?php

/**
 * Test script: Get email from mailbox and verify DKIM + content
 * 
 * Usage: php temp/test_send_email_to_verifier.php [email] [access_token] [uid] [provider]
 * 
 * If email/token not provided, will try to get from logs
 * 
 * Example:
 *   php temp/test_send_email_to_verifier.php serj@sitiy.ru <token> 12345 yandex
 *   php temp/test_send_email_to_verifier.php (will get first email, token from logs)
 * 
 * This script:
 * 1. Connects to IMAP via OAuth using ImapMonitorService
 * 2. Retrieves raw email by UID (or first email if UID not specified)
 * 3. Verifies DKIM signature
 * 4. Analyzes email content (from, subject, body)
 * 
 * Does NOT forward email - only verifies!
 */

require __DIR__ . '/../vendor/autoload.php';

// Load Laravel application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ImapMonitorService;
use App\Http\Controllers\Api\PaymentVerificationController;

// Parse command line arguments
$email = $argv[1] ?? null;
$accessToken = $argv[2] ?? null;
$uid = null;
$provider = 'yandex';
$checkAll = false;

// Parse remaining arguments
for ($i = 3; $i < count($argv); $i++) {
    if ($argv[$i] === '--all') {
        $checkAll = true;
    } elseif (is_numeric($argv[$i]) && !$uid) {
        $uid = $argv[$i];
    } elseif (in_array($argv[$i], ['yandex', 'gmail', 'outlook'])) {
        $provider = $argv[$i];
    }
}

// Try to get from logs if not provided
if (!$email || !$accessToken) {
    echo "=== Trying to get email/token from logs ===\n";
    $logFile = __DIR__ . '/../storage/logs/laravel.log';
    if (file_exists($logFile)) {
        // Read last 50KB of log file (most recent entries)
        $logContent = file_get_contents($logFile);
        $logContent = substr($logContent, -50000);
        
        // Extract email - look for "email":"user@domain.com" pattern
        if (!$email && preg_match('/"email":"([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})"/', $logContent, $matches)) {
            $email = $matches[1];
            echo "Found email in logs: $email\n";
        }
        
        // Extract token - get the last one (most recent)
        if (!$accessToken) {
            // Try to find full_token in recent logs
            if (preg_match_all('/"full_token":"([^"]+)"/', $logContent, $allMatches)) {
                $accessToken = end($allMatches[1]); // Get last token
                echo "Found token in logs: " . substr($accessToken, 0, 20) . "...\n";
            }
        }
    }
}

if (!$email || !$accessToken) {
    echo "Usage: php temp/test_send_email_to_verifier.php [email] [access_token] [uid] [provider] [--all]\n";
    echo "\n";
    echo "If email/token not provided, will try to get from logs\n";
    echo "\n";
    echo "Example:\n";
    echo "  php temp/test_send_email_to_verifier.php serj@sitiy.ru <token> 12345 yandex\n";
    echo "  php temp/test_send_email_to_verifier.php serj@sitiy.ru <token> --all (check all emails)\n";
    echo "  php temp/test_send_email_to_verifier.php (gets from logs, first email)\n";
    exit(1);
}

$verificationEmail = config('mail.verification_email');
if (!$verificationEmail) {
    echo "ERROR: PAYMENT_VERIFICATION_EMAIL is not set in .env file\n";
    exit(1);
}

echo "=== Test: Forward Email to Verification ===\n";
echo "User email: $email\n";
echo "Provider: $provider\n";
echo "Mode: " . ($checkAll ? 'check all emails' : ($uid ? "UID: $uid" : 'first email')) . "\n";
echo "Verification email: $verificationEmail\n";
echo "Token preview: " . substr($accessToken, 0, 20) . "...\n\n";

// Get IMAP settings for provider
$imapSettings = [
    'yandex' => [
        'host' => 'imap.yandex.com',
        'port' => 993,
    ],
    'gmail' => [
        'host' => 'imap.gmail.com',
        'port' => 993,
    ],
    'outlook' => [
        'host' => 'outlook.office365.com',
        'port' => 993,
    ],
];

if (!isset($imapSettings[$provider])) {
    echo "ERROR: Provider '$provider' is not supported\n";
    exit(1);
}

$host = $imapSettings[$provider]['host'];
$port = $imapSettings[$provider]['port'];

$imapService = null;
try {
    // Step 1: Connect to IMAP
    echo "=== Step 1: Connecting to IMAP ===\n";
    $imapService = new ImapMonitorService();
    
    $connected = $imapService->connectWithOAuth($email, $accessToken, $host, $port);
    
    if (!$connected) {
        echo "ERROR: Failed to connect to IMAP server\n";
        exit(1);
    }
    
    echo "✓ Connected to IMAP server\n\n";
    
    // If --all flag, check all emails
    if ($checkAll) {
        echo "=== Checking all emails in inbox ===\n";
        $emailList = $imapService->getEmailList(100, 0);
        echo "Found " . count($emailList) . " emails\n\n";
        
        $foundDkim = false;
        foreach ($emailList as $emailItem) {
            $currentUid = $emailItem['email_num'] ?? null;
            if (!$currentUid) {
                continue;
            }
            
            echo "--- Checking UID: $currentUid ---\n";
            echo "  Subject: " . ($emailItem['subject'] ?? 'N/A') . "\n";
            echo "  From: " . ($emailItem['from'] ?? 'N/A') . "\n";
            
            $rawEmail = $imapService->getRawEmailByNum((int)$currentUid);
            if (!$rawEmail) {
                echo "  ✗ Could not retrieve raw email\n\n";
                continue;
            }
            
            // Check for DKIM
            if (preg_match('/^DKIM-Signature:\s*(.+)$/mi', $rawEmail, $dkimMatch)) {
                echo "  ✓ DKIM-Signature: FOUND\n";
                $foundDkim = true;
                
                // Verify DKIM
                $dkimMethod = $reflection->getMethod('verifyDKIM');
                $dkimMethod->setAccessible(true);
                $dkimResult = $dkimMethod->invoke($controller, $rawEmail);
                
                if ($dkimResult['valid']) {
                    echo "  ✓ DKIM signature is VALID\n";
                    echo "  Domain: " . ($dkimResult['domain'] ?? 'unknown') . "\n";
                } else {
                    echo "  ✗ DKIM signature is INVALID\n";
                    if ($dkimResult['error']) {
                        echo "  Error: " . substr($dkimResult['error'], 0, 100) . "\n";
                    }
                }
            } else {
                echo "  ✗ DKIM-Signature: NOT FOUND\n";
            }
            echo "\n";
        }
        
        if ($foundDkim) {
            echo "=== Found email(s) with DKIM signature ===\n";
        } else {
            echo "=== No emails with DKIM signature found ===\n";
        }
        
        if ($imapService) {
            $imapService->disconnect();
        }
        exit(0);
    }
    
    // Step 2: Get raw email
    echo "=== Step 2: Retrieving email ===\n";
    $rawEmail = null;
    
    if ($uid) {
        // Get specific email by UID
        $rawEmail = $imapService->getRawEmailByNum((int)$uid);
        if (!$rawEmail) {
            echo "ERROR: Email with UID $uid not found\n";
            if ($imapService) {
                $imapService->disconnect();
            }
            exit(1);
        }
        echo "✓ Retrieved email by UID $uid\n";
    } else {
        // Get first email from inbox
        $emailList = $imapService->getEmailList(1, 0);
        if (empty($emailList)) {
            echo "ERROR: No emails found in inbox\n";
            if ($imapService) {
                $imapService->disconnect();
            }
            exit(1);
        }
        $firstEmail = $emailList[0];
        $firstUid = $firstEmail['email_num'] ?? null;
        if (!$firstUid) {
            echo "ERROR: Could not get UID from first email\n";
            if ($imapService) {
                $imapService->disconnect();
            }
            exit(1);
        }
        $rawEmail = $imapService->getRawEmailByNum((int)$firstUid);
        if (!$rawEmail) {
            echo "ERROR: Could not retrieve raw email for UID $firstUid\n";
            if ($imapService) {
                $imapService->disconnect();
            }
            exit(1);
        }
        echo "✓ Retrieved first email (UID: $firstUid)\n";
    }
    
    echo "  Raw email size: " . strlen($rawEmail) . " bytes\n";
    
    // Extract subject for display
    if (preg_match('/^Subject:\s*(.+)$/mi', $rawEmail, $matches)) {
        $subject = trim($matches[1]);
        $subject = mb_decode_mimeheader($subject);
        echo "  Subject: $subject\n";
    }
    
    // Extract from for display
    $fromEmail = '';
    $fromDomain = '';
    if (preg_match('/^From:\s*(.+)$/mi', $rawEmail, $matches)) {
        $from = trim($matches[1]);
        echo "  From: $from\n";
        
        // Extract email address from From header
        if (preg_match('/<([^>]+)>/', $from, $emailMatch)) {
            $fromEmail = $emailMatch[1];
        } elseif (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $from, $emailMatch)) {
            $fromEmail = $emailMatch[1];
        }
        if ($fromEmail) {
            $fromDomain = substr(strrchr($fromEmail, "@"), 1);
            echo "  From domain: $fromDomain\n";
        }
    }
    
    // Check for DKIM signature
    if (preg_match('/^DKIM-Signature:\s*(.+)$/mi', $rawEmail, $matches)) {
        echo "  DKIM-Signature: present (will be verified)\n";
    } else {
        echo "  DKIM-Signature: not found\n";
    }
    
    echo "\n";
    
    // Step 3: Verify DKIM
    echo "=== Step 3: Verifying DKIM ===\n";
    
    $controller = new PaymentVerificationController();
    $reflection = new \ReflectionClass($controller);
    $dkimMethod = $reflection->getMethod('verifyDKIM');
    $dkimMethod->setAccessible(true);
    
    $dkimResult = $dkimMethod->invoke($controller, $rawEmail);
    
    if ($dkimResult['valid']) {
        echo "✓ DKIM signature is VALID\n";
        echo "  Domain: " . ($dkimResult['domain'] ?? 'unknown') . "\n";
    } else {
        echo "✗ DKIM signature is INVALID or NOT FOUND\n";
        if ($dkimResult['error']) {
            echo "  Error: " . $dkimResult['error'] . "\n";
        }
    }
    
    if ($dkimResult['signature']) {
        echo "  Signature preview: " . substr($dkimResult['signature'], 0, 80) . "...\n";
    }
    
    echo "\n";
    
    // Step 4: Analyze email content
    echo "=== Step 4: Analyzing email content ===\n";
    
    // Extract payment data
    $extractMethod = $reflection->getMethod('extractPaymentData');
    $extractMethod->setAccessible(true);
    
    $paymentData = $extractMethod->invoke($controller, $rawEmail, $fromDomain ?: 'unknown');
    
    if ($fromDomain) {
        echo "From domain: $fromDomain\n";
    }
    if ($paymentData['amount']) {
        echo "Amount: " . $paymentData['amount'] . "\n";
    }
    if ($paymentData['date']) {
        echo "Date: " . $paymentData['date'] . "\n";
    }
    if ($paymentData['reference']) {
        echo "Reference: " . $paymentData['reference'] . "\n";
    }
    
    // Show body preview (extract text from multipart if needed)
    $bodyStart = strpos($rawEmail, "\r\n\r\n");
    $body = $bodyStart !== false ? substr($rawEmail, $bodyStart + 4, 5000) : '';
    
    // Try to extract plain text from multipart
    if (preg_match('/Content-Type:\s*text\/plain[^;]*;.*?charset=([^;\r\n]+)/i', $rawEmail, $charsetMatch)) {
        $charset = trim($charsetMatch[1]);
    } else {
        $charset = 'UTF-8';
    }
    
    // Extract text part from multipart
    if (preg_match('/Content-Type:\s*text\/plain[^\r\n]*\r\n\r\n([^\r\n]*(?:\r\n[^\r\n]*)*?)(?=\r\n--|\r\nContent-Type:|$)/is', $rawEmail, $textMatch)) {
        $bodyPreview = $textMatch[1];
    } else {
        $bodyPreview = substr($body, 0, 500);
    }
    
    $bodyPreview = strip_tags($bodyPreview);
    $bodyPreview = preg_replace('/\s+/', ' ', $bodyPreview);
    $bodyPreview = trim($bodyPreview);
    
    if ($bodyPreview) {
        echo "\nBody preview:\n";
        echo "  " . substr($bodyPreview, 0, 200) . "...\n";
    }
    
    echo "\n";
    
    // Disconnect from IMAP
    if ($imapService) {
        $imapService->disconnect();
    }
    
    echo "=== VERIFICATION COMPLETE ===\n";
    echo "DKIM: " . ($dkimResult['valid'] ? 'VALID' : 'INVALID/NOT FOUND') . "\n";
    echo "Email analyzed, NOT forwarded\n";
    
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


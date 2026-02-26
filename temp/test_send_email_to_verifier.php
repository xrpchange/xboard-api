<?php

/**
 * Test script: Verify DKIM and content of a raw email from .eml file
 * Email is read on the client only; this script uses a local .eml file.
 *
 * Usage: php temp/test_send_email_to_verifier.php <path_to.eml>
 *
 * Does NOT forward email - only verifies DKIM and parses content.
 */

require __DIR__ . '/../vendor/autoload.php';

// Load Laravel application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\PaymentVerificationController;

$emlPath = $argv[1] ?? null;
if (!$emlPath || !is_readable($emlPath)) {
    echo "Usage: php temp/test_send_email_to_verifier.php <path_to.eml>\n";
    echo "Example: php temp/test_send_email_to_verifier.php message.eml\n";
    exit(1);
}

$rawEmail = file_get_contents($emlPath);
if (!$rawEmail) {
    echo "Could not read file: $emlPath\n";
    exit(1);
}

echo "=== Test: Verify email (from .eml file) ===\n";
echo "File: $emlPath\n";
echo "Raw email size: " . strlen($rawEmail) . " bytes\n\n";

$controller = new PaymentVerificationController();
$reflection = new \ReflectionClass($controller);

// Subject / From
if (preg_match('/^Subject:\s*(.+)$/mi', $rawEmail, $matches)) {
    $subject = trim($matches[1]);
    $subject = mb_decode_mimeheader($subject);
    echo "Subject: $subject\n";
}
$fromEmail = '';
$fromDomain = '';
if (preg_match('/^From:\s*(.+)$/mi', $rawEmail, $matches)) {
    $from = trim($matches[1]);
    echo "From: $from\n";
    if (preg_match('/<([^>]+)>/', $from, $emailMatch)) {
        $fromEmail = $emailMatch[1];
    } elseif (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $from, $emailMatch)) {
        $fromEmail = $emailMatch[1];
    }
    if ($fromEmail) {
        $fromDomain = substr(strrchr($fromEmail, "@"), 1);
        echo "From domain: $fromDomain\n";
    }
}
echo "DKIM-Signature: " . (strpos($rawEmail, 'DKIM-Signature') !== false ? "present" : "not found") . "\n\n";

// Verify DKIM
echo "=== Verifying DKIM ===\n";
$dkimMethod = $reflection->getMethod('verifyDKIM');
$dkimMethod->setAccessible(true);
$dkimResult = $dkimMethod->invoke($controller, $rawEmail);

if ($dkimResult['valid']) {
    echo "✓ DKIM signature is VALID\n";
    echo "  Domain: " . ($dkimResult['domain'] ?? 'unknown') . "\n";
} else {
    echo "✗ DKIM signature is INVALID or NOT FOUND\n";
    if (!empty($dkimResult['error'])) {
        echo "  Error: " . $dkimResult['error'] . "\n";
    }
}
if (!empty($dkimResult['signature'])) {
    echo "  Signature preview: " . substr($dkimResult['signature'], 0, 80) . "...\n";
}
echo "\n";

// Extract payment data
echo "=== Payment data ===\n";
$extractMethod = $reflection->getMethod('extractPaymentData');
$extractMethod->setAccessible(true);
$paymentData = $extractMethod->invoke($controller, $rawEmail, $fromDomain ?: 'unknown');
if ($paymentData['amount']) echo "Amount: " . $paymentData['amount'] . "\n";
if ($paymentData['date']) echo "Date: " . $paymentData['date'] . "\n";
if ($paymentData['reference']) echo "Reference: " . $paymentData['reference'] . "\n";

// Body preview
$bodyStart = strpos($rawEmail, "\r\n\r\n");
$body = $bodyStart !== false ? substr($rawEmail, $bodyStart + 4, 5000) : '';
if (preg_match('/Content-Type:\s*text\/plain[^\r\n]*\r\n\r\n([^\r\n]*(?:\r\n[^\r\n]*)*?)(?=\r\n--|\r\nContent-Type:|$)/is', $rawEmail, $textMatch)) {
    $bodyPreview = $textMatch[1];
} else {
    $bodyPreview = substr($body, 0, 500);
}
$bodyPreview = strip_tags($bodyPreview);
$bodyPreview = preg_replace('/\s+/', ' ', trim($bodyPreview));
if ($bodyPreview) {
    echo "\nBody preview: " . substr($bodyPreview, 0, 200) . "...\n";
}

echo "\n=== VERIFICATION COMPLETE ===\n";
echo "DKIM: " . ($dkimResult['valid'] ? 'VALID' : 'INVALID/NOT FOUND') . "\n";
echo "Email analyzed, NOT forwarded\n";

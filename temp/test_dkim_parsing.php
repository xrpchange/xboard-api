<?php

/**
 * Test script: Parse DKIM and headers from raw email (.eml file)
 * Email is read on the client only; this script uses a local .eml file.
 *
 * Usage: php temp/test_dkim_parsing.php <path_to.eml>
 */

require __DIR__ . '/../vendor/autoload.php';

// Load Laravel application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\PaymentVerificationController;

$emlPath = $argv[1] ?? null;
if (!$emlPath || !is_readable($emlPath)) {
    die("Usage: php temp/test_dkim_parsing.php <path_to.eml>\n" .
        "Example: php temp/test_dkim_parsing.php message.eml\n");
}

$rawEmail = file_get_contents($emlPath);
if (!$rawEmail) {
    die("Could not read file: $emlPath\n");
}

echo "=== Test: DKIM Parsing (from .eml file) ===\n";
echo "File: $emlPath\n";
echo "Raw email length: " . strlen($rawEmail) . " bytes\n";
echo "First 200 chars: " . substr($rawEmail, 0, 200) . "\n\n";

// Analyze raw email
echo "=== Analyzing raw email ===\n";
if (preg_match('/^[A-Za-z-]+:/', $rawEmail)) {
    echo "✓ Email starts with headers (good!)\n";
} else {
    echo "✗ Email does NOT start with headers\n";
}
echo "  Has DKIM-Signature: " . (strpos($rawEmail, 'DKIM-Signature') !== false ? "YES" : "NO") . "\n";
echo "  Has From header: " . (strpos($rawEmail, 'From:') !== false ? "YES" : "NO") . "\n";
echo "\n  First 1000 characters:\n";
echo "  " . str_replace("\n", "\n  ", substr($rawEmail, 0, 1000)) . "\n\n";

// Parse DKIM using controller method
echo "=== Parsing DKIM using controller method ===\n";
$controller = new PaymentVerificationController();
$reflection = new \ReflectionClass($controller);
$dkimMethod = $reflection->getMethod('verifyDKIM');
$dkimMethod->setAccessible(true);
$dkimResult = $dkimMethod->invoke($controller, $rawEmail);

echo "  valid: " . ($dkimResult['valid'] ? 'true' : 'false') . "\n";
echo "  domain: " . ($dkimResult['domain'] ?? 'null') . "\n";
echo "  error: " . ($dkimResult['error'] ?? 'null') . "\n";
echo "  signature: " . ($dkimResult['signature'] ? substr($dkimResult['signature'], 0, 80) . '...' : 'null') . "\n";
if ($dkimResult['valid']) {
    echo "✓ DKIM signature is VALID\n";
} else {
    echo "✗ DKIM signature is INVALID or NOT FOUND\n";
}

echo "\n=== Parsing From header ===\n";
$extractMethod = $reflection->getMethod('extractSenderInfo');
$extractMethod->setAccessible(true);
$senderInfo = $extractMethod->invoke($controller, $rawEmail);
echo "  Sender email: " . ($senderInfo['email'] ?? 'NOT FOUND') . "\n";
echo "  Sender domain: " . ($senderInfo['domain'] ?? 'NOT FOUND') . "\n";

echo "\n=== All headers ===\n";
$lines = explode("\n", $rawEmail);
$inHeaders = true;
$headerCount = 0;
foreach ($lines as $line) {
    if (trim($line) === '') {
        if ($inHeaders) {
            $inHeaders = false;
            echo "  (end of headers)\n";
            break;
        }
    }
    if ($inHeaders && preg_match('/^([A-Za-z-]+):\s*(.+)$/', $line, $matches)) {
        $headerCount++;
        echo "  " . $matches[1] . ": " . substr($matches[2], 0, 80) . "\n";
    }
}
echo "  Total headers: $headerCount\n";

echo "\n=== TEST COMPLETE ===\n";

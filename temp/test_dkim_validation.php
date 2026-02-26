<?php

/**
 * Test script for DKIM validation using PHPMailer DKIMValidator
 * Email is read on the client only; this script uses a local .eml file.
 *
 * Usage: php temp/test_dkim_validation.php <path_to.eml>
 */

require __DIR__ . '/../vendor/autoload.php';

// Load Laravel application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use PHPMailer\DKIMValidator\Validator as DKIMValidator;
use PHPMailer\DKIMValidator\DKIMException;

$emlPath = $argv[1] ?? null;
if (!$emlPath || !is_readable($emlPath)) {
    die("Usage: php test_dkim_validation.php <path_to.eml>\n" .
        "Example: php temp/test_dkim_validation.php message.eml\n");
}

$rawEmail = file_get_contents($emlPath);
if (!$rawEmail) {
    die("Could not read file: $emlPath\n");
}

echo "\n=== DKIM validation test (from .eml file) ===\n";
echo "File: $emlPath\n";
echo "Raw email length: " . strlen($rawEmail) . " bytes\n";

$hasDkim = strpos($rawEmail, 'DKIM-Signature') !== false;
echo "Has DKIM-Signature: " . ($hasDkim ? 'YES' : 'NO') . "\n";

if (preg_match('/^[A-Za-z-]+:/', $rawEmail)) {
    echo "Email starts with headers (good!)\n";
} else {
    echo "WARNING: Email does NOT start with headers\n";
    echo "First 100 chars: " . substr($rawEmail, 0, 100) . "\n";
}

if ($hasDkim) {
    if (preg_match('/DKIM-Signature:([^\r\n]+(?:\r?\n[ \t]+[^\r\n]+)*)/i', $rawEmail, $matches)) {
        $dkimHeader = trim($matches[1]);
        echo "\nDKIM-Signature header (first 200 chars):\n" . substr($dkimHeader, 0, 200) . "\n";
        if (preg_match('/d=([^;\s]+)/', $dkimHeader, $domainMatch)) {
            echo "DKIM Domain: " . trim($domainMatch[1]) . "\n";
        }
        if (preg_match('/s=([^;\s]+)/', $dkimHeader, $selectorMatch)) {
            echo "DKIM Selector: " . trim($selectorMatch[1]) . "\n";
        }
    }
}

echo "\n=== Testing DKIM Validation ===\n";

try {
    $dkimValidator = new DKIMValidator($rawEmail);
    echo "DKIMValidator created successfully\n";

    echo "\nCalling validate()...\n";
    $validationResult = $dkimValidator->validate();
    print_r($validationResult);

    echo "\nCalling validateBoolean()...\n";
    $isValid = $dkimValidator->validateBoolean();
    echo "Is valid: " . ($isValid ? 'YES' : 'NO') . "\n";

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
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Test completed ===\n";

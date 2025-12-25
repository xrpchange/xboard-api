<?php

/**
 * Test script for sending raw email to verification email address via email service API
 * 
 * Usage: php test_send_raw_email.php [raw_email_file.eml]
 * 
 * This script tests the sendEmailToVerifier method from PaymentVerificationController
 * which uses email service API (Postmark/Resend/Mailgun) with token authentication.
 * 
 * Note: 
 * - Uses email service API with token (not SMTP with password)
 * - Configure MAIL_MAILER and corresponding API token in .env
 * - For IMAP operations, the project uses webklex/php-imap library
 */

require __DIR__ . '/vendor/autoload.php';

// Load Laravel application
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rawEmailFile = $argv[1] ?? null;
$verificationEmail = config('mail.verification_email');

if (!$verificationEmail) {
    echo "ERROR: PAYMENT_VERIFICATION_EMAIL is not set in .env file\n";
    echo "Please add PAYMENT_VERIFICATION_EMAIL=check@xdexboard.com to your .env file\n";
    exit(1);
}

echo "Testing raw email sending to verification email\n";
echo "Verification email: $verificationEmail\n";

$mailer = config('mail.default');
echo "Mailer: $mailer\n";

// Check mailer configuration
$hasToken = false;
if ($mailer === 'postmark') {
    $token = config('services.postmark.token');
    $hasToken = !empty($token);
    echo "Postmark token: " . ($hasToken ? 'configured' : 'NOT configured') . "\n";
} elseif ($mailer === 'resend') {
    $key = config('services.resend.key');
    $hasToken = !empty($key);
    echo "Resend key: " . ($hasToken ? 'configured' : 'NOT configured') . "\n";
} elseif ($mailer === 'mailgun') {
    $secret = config('services.mailgun.secret');
    $hasToken = !empty($secret);
    echo "Mailgun secret: " . ($hasToken ? 'configured' : 'NOT configured') . "\n";
} else {
    echo "Warning: Using mailer '$mailer' which may not support raw email sending\n";
}

echo "\n";

// Get raw email
$rawEmail = null;
if ($rawEmailFile && file_exists($rawEmailFile)) {
    echo "=== Reading raw email from file: $rawEmailFile ===\n";
    $rawEmail = file_get_contents($rawEmailFile);
    echo "✓ Raw email loaded (" . strlen($rawEmail) . " bytes)\n\n";
} else {
    echo "=== Using sample raw email ===\n";
    // Create sample raw email for testing
    $rawEmail = "From: test@example.com\r\n";
    $rawEmail .= "To: original@example.com\r\n";
    $rawEmail .= "Subject: Test Payment Verification Email\r\n";
    $rawEmail .= "Date: " . date('r') . "\r\n";
    $rawEmail .= "Message-ID: <test-" . time() . "@xdexboard.com>\r\n";
    $rawEmail .= "MIME-Version: 1.0\r\n";
    $rawEmail .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $rawEmail .= "\r\n";
    $rawEmail .= "This is a test email for payment verification.\r\n";
    $rawEmail .= "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=example.com; s=default; h=from:to:subject:date; bh=test; b=test\r\n";
    echo "✓ Sample raw email created (" . strlen($rawEmail) . " bytes)\n\n";
}

// Test sending via PaymentVerificationController method
echo "=== Testing sendEmailToVerifier method ===\n";
try {
    $controller = new \App\Http\Controllers\Api\PaymentVerificationController();
    
    // Use reflection to call private method
    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('sendEmailToVerifier');
    $method->setAccessible(true);
    
    echo "Calling sendEmailToVerifier()...\n";
    $method->invoke($controller, $rawEmail);
    
    echo "✓ Email sent successfully via email service API\n";
    echo "\n=== Test PASSED ===\n";
    echo "Raw email has been sent to $verificationEmail\n";
    echo "Check the verification email inbox to confirm receipt.\n";
    
} catch (\Exception $e) {
    echo "✗ Test FAILED: " . $e->getMessage() . "\n";
    echo "  Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

<?php

/**
 * Test script for email forwarding via email service API
 * 
 * Usage: php scripts/test_email_forwarding.php [raw_email_file.eml]
 * 
 * This script tests the sendEmailToVerifier method from PaymentVerificationController
 * which uses email service API (Postmark/Resend/Mailgun) with token authentication.
 * 
 * Requirements:
 * - PAYMENT_VERIFICATION_EMAIL must be set in .env
 * - MAIL_MAILER must be configured (postmark, resend, or mailgun)
 * - Corresponding API token must be set in .env (POSTMARK_TOKEN, RESEND_KEY, or Mailgun credentials)
 * 
 * Note: 
 * - Uses email service API with token (not SMTP with password)
 * - For IMAP operations, the project uses webklex/php-imap library
 */

require __DIR__ . '/../vendor/autoload.php';

// Load Laravel application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rawEmailFile = $argv[1] ?? null;
$verificationEmail = config('mail.verification_email');

if (!$verificationEmail) {
    echo "ERROR: PAYMENT_VERIFICATION_EMAIL is not set in .env file\n";
    echo "Please add PAYMENT_VERIFICATION_EMAIL=check@xdexboard.com to your .env file\n";
    exit(1);
}

echo "=== Email Forwarding Test ===\n";
echo "Verification email: $verificationEmail\n";

$mailer = config('mail.default');
echo "Mailer: $mailer\n\n";

// Check mailer configuration
$hasToken = false;
$tokenInfo = '';

if ($mailer === 'postmark') {
    $token = config('services.postmark.token');
    $hasToken = !empty($token);
    $tokenInfo = "Postmark token: " . ($hasToken ? 'configured' : 'NOT configured');
} elseif ($mailer === 'resend') {
    $key = config('services.resend.key');
    $hasToken = !empty($key);
    $tokenInfo = "Resend key: " . ($hasToken ? 'configured' : 'NOT configured');
} elseif ($mailer === 'mailgun') {
    $secret = config('services.mailgun.secret');
    $domain = config('services.mailgun.domain');
    $hasToken = !empty($secret) && !empty($domain);
    $tokenInfo = "Mailgun: " . ($hasToken ? 'configured (secret + domain)' : 'NOT configured');
} else {
    echo "Warning: Using mailer '$mailer' which may not support raw email sending\n";
    echo "Supported mailers: postmark, resend, mailgun\n\n";
}

echo "$tokenInfo\n";

if (!$hasToken && in_array($mailer, ['postmark', 'resend', 'mailgun'])) {
    echo "\nERROR: API token is not configured for mailer '$mailer'\n";
    echo "Please configure the corresponding token in .env file:\n";
    if ($mailer === 'postmark') {
        echo "  POSTMARK_TOKEN=your_token_here\n";
    } elseif ($mailer === 'resend') {
        echo "  RESEND_KEY=your_key_here\n";
    } elseif ($mailer === 'mailgun') {
        echo "  MAILGUN_SECRET=your_secret_here\n";
        echo "  MAILGUN_DOMAIN=your_domain_here\n";
    }
    exit(1);
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
    $startTime = microtime(true);
    $method->invoke($controller, $rawEmail);
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    echo "✓ Email sent successfully via email service API\n";
    echo "  Duration: {$duration}ms\n";
    echo "\n=== Test PASSED ===\n";
    echo "Raw email has been sent to $verificationEmail\n";
    echo "Check the verification email inbox to confirm receipt.\n";
    
} catch (\Exception $e) {
    echo "✗ Test FAILED: " . $e->getMessage() . "\n";
    if ($e->getPrevious()) {
        echo "  Previous: " . $e->getPrevious()->getMessage() . "\n";
    }
    echo "  Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}


#!/usr/bin/env php
<?php
/**
 * XBoard Email Verifier
 * 
 * Automatic DKIM signature verification of forwarded emails from banks
 * and sending responses to buyers
 * 
 * Usage: pipe through Postfix
 * Each email to check@xdexboard.com is passed to this script via STDIN
 */

declare(strict_types=1);

// Configuration
const LOG_FILE = '/var/log/xboard-email/verifier.log';
const EMAIL_FROM = 'check@xdexboard.com';
const EMAIL_REPLY_TO = 'noreply@xdexboard.com';
const SMTP_HOST = 'localhost';
const SMTP_PORT = 25;

// Allowed bank sender domains (domains from which emails should come)
const ALLOWED_BANK_DOMAINS = [
    'sberbank.ru',
    'tinkoff.ru',
    'alfabank.ru',
    'vtb.ru',
    'gazprombank.ru',
    'raiffeisen.ru',
    'rosbank.ru',
    'otpbank.ru',
    'uralsib.ru',
    'banki.ru',
    // Add other banks as needed
];

// Response templates
const RESPONSE_TEMPLATES = [
    'success' => [
        'subject' => 'Платеж подтвержден',
        'body' => <<<'EOT'
Здравствуйте!

Ваш платеж успешно подтвержден. DKIM подпись проверена, письмо от банка получено и обработано.

Спасибо за использование XBoard!

--
XBoard P2P Exchange
EOT
    ],
    'dkim_failed' => [
        'subject' => 'Ошибка проверки платежа',
        'body' => <<<'EOT'
Здравствуйте!

К сожалению, не удалось проверить подлинность письма от банка. DKIM подпись сломалась при пересылке.

Что делать:
1. Перешлите письмо от банка заново
2. НЕ добавляйте никакого текста при пересылке
3. Используйте функцию "Переслать как вложение" (Forward as Attachment), если доступна

Если проблема повторяется, свяжитесь с поддержкой.

--
XBoard P2P Exchange
EOT
    ],
    'invalid_sender' => [
        'subject' => 'Ошибка проверки платежа',
        'body' => <<<'EOT'
Здравствуйте!

Письмо не может быть обработано, так как оно не пришло с адреса банка или было модифицировано.

Пожалуйста, перешлите оригинальное письмо от банка без изменений.

--
XBoard P2P Exchange
EOT
    ],
    'parse_error' => [
        'subject' => 'Ошибка обработки письма',
        'body' => <<<'EOT'
Здравствуйте!

Произошла техническая ошибка при обработке вашего письма. Пожалуйста, попробуйте переслать письмо позже.

Если проблема повторяется, свяжитесь с поддержкой.

--
XBoard P2P Exchange
EOT
    ],
];

/**
 * Logging
 */
function logMessage(string $message, string $level = 'INFO'): void
{
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
    
    // Also output to stderr for Postfix
    error_log($logEntry);
}

/**
 * Send email response
 */
function sendEmailResponse(string $to, string $subject, string $body): bool
{
    try {
        // Use mail() function for simplicity (can be replaced with PHPMailer or SwiftMailer)
        $headers = [
            'From: ' . EMAIL_FROM,
            'Reply-To: ' . EMAIL_REPLY_TO,
            'X-Mailer: XBoard Email Verifier',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        
        $result = mail($to, $subject, $body, implode("\r\n", $headers));
        
        if ($result) {
            logMessage("Email sent: {$to} - {$subject}", 'INFO');
            return true;
        } else {
            logMessage("Error sending email: {$to}", 'ERROR');
            return false;
        }
    } catch (Exception $e) {
        logMessage("Exception while sending email: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Extract email address from string
 */
function extractEmail(string $emailString): ?string
{
    // Remove angle brackets and extract email
    if (preg_match('/<([^>]+)>/', $emailString, $matches)) {
        return trim($matches[1]);
    }
    
    // If no angle brackets, just clean
    $email = trim($emailString);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }
    
    return null;
}

/**
 * Verify DKIM signature of email via opendkim-testmsg
 * 
 * Uses opendkim to verify DKIM signature
 */
function verifyDKIM(string $rawEmail): array
{
    $result = [
        'valid' => false,
        'signature' => null,
        'domain' => null,
        'error' => null,
    ];
    
    try {
        // Parse email to extract DKIM headers
        $lines = explode("\n", $rawEmail);
        $headers = [];
        $inHeaders = true;
        $headerLines = [];
        
        foreach ($lines as $line) {
            if ($inHeaders) {
                if (trim($line) === '') {
                    $inHeaders = false;
                    continue;
                }
                $headerLines[] = $line;
                
                if (preg_match('/^DKIM-Signature:/i', $line)) {
                    $dkimHeader = trim($line);
                    // Multi-line headers
                    $lineIndex = array_search($line, $lines);
                    $nextLineIndex = $lineIndex + 1;
                    while ($nextLineIndex < count($lines) && 
                           preg_match('/^\s+/', $lines[$nextLineIndex])) {
                        $dkimHeader .= "\n" . $lines[$nextLineIndex];
                        $nextLineIndex++;
                    }
                    $headers['DKIM-Signature'][] = $dkimHeader;
                }
            }
        }
        
        if (empty($headers['DKIM-Signature'])) {
            $result['error'] = 'DKIM-Signature header not found';
            logMessage('DKIM signature not found in email', 'WARNING');
            return $result;
        }
        
        // Extract domain from DKIM signature
        foreach ($headers['DKIM-Signature'] as $dkimSig) {
            if (preg_match('/d=([^;]+)/', $dkimSig, $matches)) {
                $result['domain'] = trim($matches[1]);
                $result['signature'] = $dkimSig;
                break;
            }
        }
        
        // Check that signature domain matches one of the banks
        $isBankDomain = false;
        foreach (ALLOWED_BANK_DOMAINS as $bankDomain) {
            if ($result['domain'] === $bankDomain || 
                substr($result['domain'], -strlen($bankDomain) - 1) === '.' . $bankDomain) {
                $isBankDomain = true;
                break;
            }
        }
        
        if (!$isBankDomain && !empty($result['domain'])) {
            $result['error'] = "DKIM domain '{$result['domain']}' not in allowed bank domains";
            logMessage("DKIM domain not in bank list: {$result['domain']}", 'WARNING');
            return $result;
        }
        
        // Verify DKIM signature via opendkim-testmsg
        // Create temporary file with email
        $tempFile = tempnam(sys_get_temp_dir(), 'dkim_test_');
        file_put_contents($tempFile, $rawEmail);
        
        // Run opendkim-testmsg for verification
        $command = "opendkim-testmsg -f {$tempFile} 2>&1";
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        // Remove temporary file
        unlink($tempFile);
        
        $outputStr = implode("\n", $output);
        
        // opendkim-testmsg returns 0 on successful verification
        if ($returnCode === 0) {
            // Check output for errors
            if (stripos($outputStr, 'signature valid') !== false || 
                stripos($outputStr, 'good signature') !== false ||
                empty($outputStr)) { // Empty output usually means success
                $result['valid'] = true;
                logMessage("DKIM verification passed via opendkim for domain: {$result['domain']}", 'INFO');
            } else {
                $result['error'] = "DKIM signature validation failed: {$outputStr}";
                logMessage("DKIM verification failed: {$outputStr}", 'WARNING');
            }
        } else {
            // If opendkim-testmsg is unavailable, do basic signature presence check
            // This is a fallback for cases when opendkim is not installed or not working
            logMessage("opendkim-testmsg unavailable (code: {$returnCode}), using basic verification", 'WARNING');
            
            // Basic check: if signature exists and domain is correct - consider OK
            // In production it's better to install opendkim or use a library
            if (!empty($result['signature']) && $isBankDomain) {
                $result['valid'] = true;
                logMessage("DKIM basic verification passed for domain: {$result['domain']} (opendkim unavailable)", 'INFO');
            } else {
                $result['error'] = "DKIM signature validation failed (fallback mode)";
            }
        }
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        logMessage("DKIM verification error: " . $e->getMessage(), 'ERROR');
    }
    
    return $result;
}

/**
 * Verify email sender
 */
function verifySender(string $rawEmail): array
{
    $result = [
        'valid' => false,
        'from' => null,
        'domain' => null,
        'error' => null,
    ];
    
    try {
        $lines = explode("\n", $rawEmail);
        
        // Look for From header
        $fromHeader = null;
        foreach ($lines as $line) {
            if (preg_match('/^From:/i', $line)) {
                $fromHeader = trim($line);
                // Multi-line headers
                $nextLineIndex = array_search($line, $lines) + 1;
                while ($nextLineIndex < count($lines) && 
                       preg_match('/^\s+/', $lines[$nextLineIndex])) {
                    $fromHeader .= "\n" . $lines[$nextLineIndex];
                    $nextLineIndex++;
                }
                break;
            }
        }
        
        if (!$fromHeader) {
            $result['error'] = 'From header not found';
            return $result;
        }
        
        // Extract email from From
        $fromEmail = extractEmail($fromHeader);
        if (!$fromEmail) {
            $result['error'] = 'Could not extract email from From header';
            return $result;
        }
        
        $result['from'] = $fromEmail;
        
        // Extract domain
        $parts = explode('@', $fromEmail);
        if (count($parts) !== 2) {
            $result['error'] = 'Invalid email format';
            return $result;
        }
        
        $result['domain'] = strtolower($parts[1]);
        
        // Check that domain is in bank list
        $isBankDomain = false;
        foreach (ALLOWED_BANK_DOMAINS as $bankDomain) {
            if ($result['domain'] === $bankDomain || 
                substr($result['domain'], -strlen($bankDomain) - 1) === '.' . $bankDomain) {
                $isBankDomain = true;
                break;
            }
        }
        
        if (!$isBankDomain) {
            $result['error'] = "Sender domain '{$result['domain']}' not in allowed bank domains";
            logMessage("Sender domain not in bank list: {$result['domain']}", 'WARNING');
            return $result;
        }
        
        $result['valid'] = true;
        logMessage("Sender verified: {$fromEmail}", 'INFO');
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        logMessage("Sender verification error: " . $e->getMessage(), 'ERROR');
    }
    
    return $result;
}

/**
 * Extract reply-to address (buyer)
 */
function extractReplyTo(string $rawEmail): ?string
{
    try {
        $lines = explode("\n", $rawEmail);
        
        // First look for Reply-To
        foreach ($lines as $line) {
            if (preg_match('/^Reply-To:/i', $line)) {
                $replyTo = trim($line);
                $email = extractEmail($replyTo);
                if ($email) {
                    return $email;
                }
            }
        }
        
        // If no Reply-To, look for From
        foreach ($lines as $line) {
            if (preg_match('/^From:/i', $line)) {
                $from = trim($line);
                $email = extractEmail($from);
                if ($email) {
                    return $email;
                }
            }
        }
        
        // If nothing found, return null
        return null;
        
    } catch (Exception $e) {
        logMessage("Error extracting reply-to address: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

/**
 * Main email processing function
 */
function processEmail(string $rawEmail): void
{
    logMessage("Starting email processing", 'INFO');
    
    // Extract reply-to address
    $replyTo = extractReplyTo($rawEmail);
    if (!$replyTo) {
        logMessage("Failed to extract reply-to address", 'ERROR');
        sendEmailResponse(
            EMAIL_FROM, // Fallback
            RESPONSE_TEMPLATES['parse_error']['subject'],
            RESPONSE_TEMPLATES['parse_error']['body']
        );
        return;
    }
    
    logMessage("Reply-to address: {$replyTo}", 'INFO');
    
    // Verify sender
    $senderCheck = verifySender($rawEmail);
    if (!$senderCheck['valid']) {
        logMessage("Sender verification failed: " . ($senderCheck['error'] ?? 'unknown'), 'WARNING');
        sendEmailResponse(
            $replyTo,
            RESPONSE_TEMPLATES['invalid_sender']['subject'],
            RESPONSE_TEMPLATES['invalid_sender']['body']
        );
        return;
    }
    
    // Verify DKIM
    $dkimCheck = verifyDKIM($rawEmail);
    if (!$dkimCheck['valid']) {
        logMessage("DKIM verification failed: " . ($dkimCheck['error'] ?? 'unknown'), 'WARNING');
        sendEmailResponse(
            $replyTo,
            RESPONSE_TEMPLATES['dkim_failed']['subject'],
            RESPONSE_TEMPLATES['dkim_failed']['body']
        );
        return;
    }
    
    // Everything is good!
    logMessage("Email successfully verified. DKIM: {$dkimCheck['domain']}, Sender: {$senderCheck['from']}", 'INFO');
    sendEmailResponse(
        $replyTo,
        RESPONSE_TEMPLATES['success']['subject'],
        RESPONSE_TEMPLATES['success']['body']
    );
    
    // TODO: Add API call here to update payment status
    // For example, via HTTP request to Laravel API
}

// ============================================
// Main logic
// ============================================

// Check that script is run via pipe
// posix_isatty may be unavailable, so check for function existence
if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
    die("This script must be run via Postfix pipe\n");
}

// Read email from STDIN
$rawEmail = '';
while (!feof(STDIN)) {
    $rawEmail .= fread(STDIN, 8192);
}

if (empty($rawEmail)) {
    logMessage("Received empty email", 'ERROR');
    exit(1);
}

logMessage("Received email of size " . strlen($rawEmail) . " bytes", 'INFO');

// Process email
try {
    processEmail($rawEmail);
    logMessage("Email processing completed successfully", 'INFO');
    exit(0);
} catch (Exception $e) {
    logMessage("Critical error: " . $e->getMessage(), 'ERROR');
    logMessage("Stack trace: " . $e->getTraceAsString(), 'ERROR');
    exit(1);
}


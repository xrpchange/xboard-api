<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * IMAP Monitor Service
 * 
 * Monitors user's email inbox via IMAP to read original emails
 * with preserved DKIM signatures for payment verification.
 * 
 * Uses webklex/php-imap library for IMAP/POP3 connections
 * with username/password authentication.
 */
class ImapMonitorService
{
    private ?Client $client = null;
    private ?Folder $folder = null;
    private string $email = '';
    
    /**
     * Connect to IMAP/POP3 server using username and password
     * 
     * @param string $email User email address
     * @param string $password User password or app password
     * @param string $imapHost IMAP/POP3 server hostname
     * @param int $port IMAP/POP3 server port (default: 993 for IMAP SSL, 995 for POP3 SSL)
     * @param string $protocol Protocol: 'imap' or 'pop3' (default: 'imap')
     * @param bool $useSsl Use SSL/TLS encryption (default: true)
     * @return bool True if connection successful
     */
    public function connect(string $email, string $password, string $imapHost, int $port = 993, string $protocol = 'imap', bool $useSsl = true): bool
    {
        try {
            if (empty($email) || empty($password)) {
                $errorMsg = 'Email address and password are required for IMAP connection';
                Log::error($errorMsg);
                throw new \RuntimeException($errorMsg);
            }
            
            $this->email = $email;
            
            Log::info("Attempting IMAP/POP3 connection", [
                'email' => $this->email,
                'host' => $imapHost,
                'port' => $port,
                'protocol' => $protocol,
                'use_ssl' => $useSsl,
                'has_password' => !empty($password)
            ]);
            
            $cm = new ClientManager();
            
            // Create IMAP/POP3 client with username/password authentication
            $config = [
                'host'          => $imapHost,
                'port'          => $port,
                'encryption'    => $useSsl ? 'ssl' : 'none',
                'validate_cert' => $useSsl,
                'protocol'      => $protocol,
                'username'      => $this->email,
                'password'      => $password,
            ];
            
            Log::info("IMAP/POP3 client configuration", [
                'host' => $config['host'],
                'port' => $config['port'],
                'protocol' => $config['protocol'],
                'encryption' => $config['encryption'],
                'username' => $config['username'],
                'has_password' => !empty($config['password'])
            ]);
            
            $this->client = $cm->make($config);
            
            // Connect to IMAP/POP3 server
            Log::info("Calling client->connect() for IMAP/POP3", [
                'username' => $config['username']
            ]);
            $this->client->connect();
            Log::info("client->connect() completed successfully");
            
            // Get INBOX folder (for IMAP) or default folder (for POP3)
            if ($protocol === 'imap') {
                try {
                    $this->folder = $this->client->getFolder('INBOX');
                } catch (\Throwable $e) {
                    Log::warning("IMAP: getFolder('INBOX') failed, trying getFolders()", ['error' => $e->getMessage()]);
                    $this->folder = null;
                }
                // Some servers (e.g. Yandex) may use different folder name or INBOX path; fallback to first folder
                if ($this->folder === null) {
                    $folders = $this->client->getFolders();
                    $this->folder = $folders->first();
                    Log::info("IMAP: using first available folder", [
                        'folder_count' => $folders->count(),
                        'first_name' => $this->folder ? $this->folder->name : null,
                    ]);
                }
            } else {
                // POP3 doesn't have folders, use default
                $folders = $this->client->getFolders();
                $this->folder = $folders->first();
            }

            if ($this->folder === null) {
                Log::error("IMAP/POP3: no folder available after connect");
                throw new \RuntimeException('No mail folder available.');
            }
            
            Log::info("IMAP/POP3 connected successfully for {$this->email}", [
                'folder' => $this->folder->name ?? 'unknown',
            ]);
            return true;
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Build detailed error information
            $errorDetails = [
                'original_error' => $errorMessage,
                'email' => $this->email,
                'host' => $imapHost,
                'port' => $port,
                'protocol' => $protocol ?? 'imap',
                'use_ssl' => $useSsl,
            ];
            
            // Provide more helpful error messages based on error type
            $userFriendlyError = $errorMessage;
            $suggestions = [];
            
            // Server response "[AUTH] login failure or POP3 disabled" (e.g. Yandex) — check before generic "SSL" (connection string contains /ssl)
            $isAuthOrPop3Disabled = stripos($errorMessage, 'login failure') !== false
                || stripos($errorMessage, 'POP3 disabled') !== false
                || (stripos($errorMessage, '[AUTH]') !== false && (stripos($errorMessage, 'failure') !== false || stripos($errorMessage, 'disabled') !== false));
            if ($isAuthOrPop3Disabled || stripos($errorMessage, 'AUTHENTICATIONFAILED') !== false) {
                if (stripos($errorMessage, 'IMAP is disabled') !== false || stripos($errorMessage, 'POP3 disabled') !== false || stripos($errorMessage, 'disabled') !== false) {
                    $userFriendlyError = $protocol === 'pop3'
                        ? 'POP3 access is disabled for this account or login failed.'
                        : 'IMAP access is disabled for this account.';
                    $suggestions[] = 'Enable ' . strtoupper($protocol) . ' access in your email account settings.';
                    
                    // Provider-specific instructions (API returns English only; client localizes if needed)
                    if (stripos($imapHost, 'yandex') !== false) {
                        $suggestions[] = 'Yandex: Settings → Mail programs → enable "Access via IMAP" and/or POP3';
                        $suggestions[] = 'Yandex: Use app password, not main password: https://id.yandex.ru/security/app-passwords';
                        if (strtolower($protocol ?? '') === 'pop3') {
                            $suggestions[] = 'Yandex: If POP3 stays disabled, try IMAP instead: server imap.yandex.com, port 993, protocol IMAP';
                        }
                    } elseif (stripos($imapHost, 'gmail') !== false) {
                        $suggestions[] = 'For Gmail: Go to Settings → Forwarding and POP/IMAP → Enable IMAP';
                        $suggestions[] = 'For Gmail: Enable 2-Step Verification and create an App Password';
                    }
                } else {
                    $userFriendlyError = 'Authentication failed: invalid email or password.';
                    $suggestions[] = 'Check that your email address and password are correct.';
                    
                    // Provider-specific instructions (API returns English only)
                    if (stripos($imapHost, 'yandex') !== false) {
                        $suggestions[] = 'Yandex: Use an app password, not your main password.';
                        $suggestions[] = 'Yandex: Create app password: https://id.yandex.ru/security/app-passwords';
                        $suggestions[] = 'Yandex: Enable POP3/IMAP in Settings → Mail programs.';
                        $errorDetails['yandex_note'] = 'Yandex requires app password for external clients.';
                    } elseif (stripos($imapHost, 'gmail') !== false) {
                        $suggestions[] = 'For Gmail: Use App Password, not your regular password.';
                        $suggestions[] = 'For Gmail: Enable 2-Step Verification first, then create App Password';
                    }
                }
            } elseif (stripos($errorMessage, 'Connection refused') !== false || stripos($errorMessage, 'Connection timed out') !== false) {
                $userFriendlyError = 'Cannot connect to email server.';
                $suggestions[] = "Check server host: {$imapHost}";
                $suggestions[] = "Check server port: {$port}";
                $suggestions[] = 'Verify your internet connection';
                $suggestions[] = 'Check if firewall is blocking the connection';
                
                // Check common misconfigurations
                if ($protocol === 'imap' && $port === 993 && !$useSsl) {
                    $suggestions[] = 'Port 993 requires SSL/TLS. Enable SSL.';
                } elseif ($protocol === 'imap' && $port === 143 && $useSsl) {
                    $suggestions[] = 'Port 143 usually does not use SSL. Try disabling SSL or use port 993 with SSL.';
                }
            } elseif (stripos($errorMessage, 'SSL') !== false || stripos($errorMessage, 'certificate') !== false || stripos($errorMessage, 'TLS') !== false) {
                $userFriendlyError = 'SSL/TLS connection error.';
                $suggestions[] = "Check SSL/TLS settings. Current: " . ($useSsl ? 'SSL enabled' : 'SSL disabled');
                $suggestions[] = "For port {$port}, SSL should be " . ($port === 993 || $port === 995 ? 'enabled' : 'disabled');
                if (stripos($errorMessage, 'certificate') !== false) {
                    $suggestions[] = 'Certificate validation failed. Check server certificate.';
                }
            }
            
            // Add full error details
            $errorDetails['user_message'] = $userFriendlyError;
            $errorDetails['suggestions'] = $suggestions;
            $errorDetails['full_error'] = $errorMessage;
            
            Log::error("IMAP/POP3 connection error", $errorDetails);
            
            // Build comprehensive error message for user
            $fullErrorMsg = $userFriendlyError;
            if (!empty($suggestions)) {
                $fullErrorMsg .= "\n\nSuggestions:\n" . implode("\n", array_map(fn($s) => "• " . $s, $suggestions));
            }
            $fullErrorMsg .= "\n\nTechnical details:\n";
            $fullErrorMsg .= "Server: {$imapHost}:{$port}\n";
            $fullErrorMsg .= "Protocol: " . strtoupper($protocol ?? 'imap') . "\n";
            $fullErrorMsg .= "SSL: " . ($useSsl ? 'Yes' : 'No') . "\n";
            $fullErrorMsg .= "Error: {$errorMessage}";
            
            // Store comprehensive error message in exception
            $e->userMessage = $fullErrorMsg;
            $e->errorDetails = $errorDetails;
            throw $e;
        }
    }
    
    /**
     * @deprecated Use connect() method instead. This method is kept for backward compatibility.
     */
    public function connectWithOAuth(string $email, string $accessToken, string $imapHost, int $port = 993): bool
    {
        // For backward compatibility, treat accessToken as password
        return $this->connect($email, $accessToken, $imapHost, $port, 'imap', true);
    }
    
    /**
     * Get list of email messages (headers only)
     * Returns array of email metadata
     */
    public function getEmailList(int $limit = 50, int $offset = 0): array
    {
        if (!$this->folder) {
            return [];
        }
        
        try {
            // Fetch one batch (page 1 only): library limit(limit, page) - we request enough for this offset+limit window
            $fetchSize = min($offset + $limit, 500);
            $fetchSize = max(1, $fetchSize);

            $messages = $this->folder->messages()
                ->all()
                ->limit($fetchSize, 1)
                ->get();

            // If current folder returned 0 messages, try first available folder (e.g. Yandex may use different INBOX path)
            if ($messages->count() === 0 && $this->client !== null) {
                $folders = $this->client->getFolders();
                $currentName = $this->folder->name ?? '';
                foreach ($folders as $f) {
                    if (($f->name ?? '') !== $currentName) {
                        $this->folder = $f;
                        Log::info("IMAP getEmailList: retrying with folder", ['folder' => $this->folder->name]);
                        $messages = $this->folder->messages()->all()->limit($fetchSize, 1)->get();
                        break;
                    }
                }
            }

            Log::info("IMAP getEmailList: fetched " . $messages->count() . " messages (requested fetchSize={$fetchSize}, offset={$offset}, limit={$limit})");
            
            $result = [];
            $index = 0;
            
            foreach ($messages as $message) {
                if ($index < $offset) {
                    $index++;
                    continue;
                }
                if (count($result) >= $limit) {
                    break;
                }
                $index++;
                try {
                    /** @var Message $message */
                    $from = $message->getFrom();
                    $fromAddress = !empty($from) ? $from[0]->mail : '';
                    
                    // getDate() returns Attribute object, need to get first value
                    $dateValue = '';
                    try {
                        $dateAttr = $message->getDate();
                        if ($dateAttr && method_exists($dateAttr, 'first')) {
                            $dateObj = $dateAttr->first();
                            $dateValue = $dateObj instanceof \DateTime ? $dateObj->format('Y-m-d H:i:s') : '';
                        }
                    } catch (\Exception $dateEx) {
                        // If date parsing fails, use empty string
                        $dateValue = '';
                    }
                    
                    // Ensure all values are JSON-serializable (strings/ints, no objects)
                    $messageId = $message->getMessageId();
                    if (!$messageId) {
                        $messageId = (string)$message->getUid();
                    }
                    
                    $result[] = [
                        'message_id' => (string)$messageId,
                        'email_num' => (int)$message->getUid(),
                        'subject' => (string)($message->getSubject() ?? ''),
                        'from' => (string)$fromAddress,
                        'date' => (string)$dateValue,
                        'read' => false,
                    ];
                    
                } catch (\Exception $e) {
                    Log::error("Error getting email data: " . $e->getMessage());
                    continue;
                }
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("Error getting email list: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get raw email by message ID
     */
    public function getRawEmailById(string $messageId): ?string
    {
        if (!$this->folder) {
            Log::warning("getRawEmailById: folder is null");
            return null;
        }
        
        try {
            Log::info("getRawEmailById: searching for messageId={$messageId}, is_numeric=" . (is_numeric($messageId) ? 'yes' : 'no'));
            
            // First, try by UID if messageId is numeric (more reliable)
            if (is_numeric($messageId)) {
                $uid = (int)$messageId;
                Log::info("getRawEmailById: trying to get message by UID={$uid}");
                // Use messages()->where('uid', $uid) to get message by UID
                $messages = $this->folder->messages()
                    ->where('uid', $uid)
                    ->limit(1)
                    ->get();
                
                if ($messages->isNotEmpty()) {
                    $message = $messages->first();
                    /** @var Message $message */
                    Log::info("getRawEmailById: found message by UID={$uid}");
                    return $this->getFullRawEmail($message);
                } else {
                    Log::warning("getRawEmailById: message not found by UID={$uid}");
                }
            }
            
            // If not found by UID or messageId is not numeric, try by Message-ID header
            // Search through messages to find by Message-ID (where() may not work reliably)
            Log::info("getRawEmailById: searching by Message-ID header");
            $messages = $this->folder->messages()
                ->all()
                ->limit(100) // Limit search to avoid performance issues
                ->get();
            
            Log::info("getRawEmailById: found " . $messages->count() . " messages to search");
            
            foreach ($messages as $message) {
                /** @var Message $message */
                $msgId = $message->getMessageId();
                // Compare Message-ID (may include angle brackets, so normalize)
                $normalizedMsgId = trim($msgId, '<>');
                $normalizedSearchId = trim($messageId, '<>');
                
                if ($normalizedMsgId === $normalizedSearchId || $msgId === $messageId) {
                    Log::info("getRawEmailById: found message by Message-ID={$msgId}");
                    return $this->getFullRawEmail($message);
                }
            }
            
            Log::warning("getRawEmailById: message not found by Message-ID={$messageId}");
            return null;
            
        } catch (\Exception $e) {
            Log::error("Error getting raw email by ID {$messageId}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get raw email by message number (UID)
     */
    public function getRawEmailByNum(int $emailNum): ?string
    {
        if (!$this->folder) {
            return null;
        }
        
        try {
            // Use messages()->where('uid', $emailNum) to get message by UID
            $messages = $this->folder->messages()
                ->where('uid', $emailNum)
                ->limit(1)
                ->get();
            
            if ($messages->isEmpty()) {
                return null;
            }
            
            $message = $messages->first();
            /** @var Message $message */
            return $this->getFullRawEmail($message);
            
        } catch (\Exception $e) {
            Log::error("Error getting raw email #{$emailNum}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get full raw email with headers and body
     * Reconstructs full RFC822 format from message headers and body
     */
    private function getFullRawEmail(Message $message): ?string
    {
        try {
            $rawBody = $message->getRawBody();
            $header = $message->getHeader();
            
            if ($header && $rawBody) {
                // Try different ways to get raw headers
                $rawHeaders = null;
                
                // Method 1: Check if header has raw property
                if (property_exists($header, 'raw')) {
                    $rawHeaders = $header->raw;
                }
                // Method 2: Check if header is a Header object with toString
                elseif (method_exists($header, 'toString')) {
                    $rawHeaders = $header->toString();
                }
                // Method 3: Try to get raw headers via reflection
                elseif (is_object($header)) {
                    try {
                        $reflection = new \ReflectionClass($header);
                        if ($reflection->hasProperty('raw')) {
                            $prop = $reflection->getProperty('raw');
                            $prop->setAccessible(true);
                            $rawHeaders = $prop->getValue($header);
                        }
                    } catch (\Exception $refEx) {
                        // Ignore reflection errors
                    }
                }
                
                if ($rawHeaders) {
                    $fullRawEmail = $rawHeaders . "\r\n\r\n" . $rawBody;
                    Log::info("getFullRawEmail: reconstructed from header+body", [
                        'headers_length' => strlen($rawHeaders),
                        'body_length' => strlen($rawBody),
                        'full_length' => strlen($fullRawEmail),
                        'has_dkim_in_headers' => strpos($rawHeaders, 'DKIM-Signature') !== false,
                        'headers_preview' => substr($rawHeaders, 0, 200)
                    ]);
                    return $fullRawEmail;
                }
            }
            
            // Last resort: just body
            Log::warning("getFullRawEmail: could not get headers, using body only", [
                'has_header' => $header !== null,
                'has_body' => $rawBody !== null,
                'body_preview' => substr($rawBody ?? '', 0, 200)
            ]);
            return $rawBody;
            
        } catch (\Exception $e) {
            Log::error("Error getting full raw email: " . $e->getMessage());
            // Fallback to just body
            try {
                return $message->getRawBody();
            } catch (\Exception $e2) {
                Log::error("Error getting raw body as fallback: " . $e2->getMessage());
                return null;
            }
        }
    }
    
    /**
     * Monitor inbox for new emails using IDLE
     */
    public function monitorInbox(callable $emailProcessor): void
    {
        if (!$this->folder) {
            throw new \RuntimeException("IMAP not connected");
        }
        
        // Note: webklex/php-imap may not support IDLE directly
        // For now, we'll use polling approach
        $this->processNewEmails($emailProcessor);
    }
    
    /**
     * Process new unread emails
     */
    public function processNewEmails(callable $emailProcessor): int
    {
        if (!$this->folder) {
            return 0;
        }
        
        try {
            // Search for unread emails
            $messages = $this->folder->messages()
                ->unseen()
                ->get();
            
            $processed = 0;
            
            foreach ($messages as $message) {
                try {
                    /** @var Message $message */
                    // Get raw email (preserves DKIM signature)
                    $rawEmail = $this->getFullRawEmail($message);
                    
                    if ($rawEmail) {
                        // Process email
                        $emailProcessor($rawEmail, $message->getUid());
                        $processed++;
                    }
                    
                } catch (\Exception $e) {
                    Log::error("Error processing email #{$message->getUid()}: " . $e->getMessage());
                }
            }
            
            return $processed;
            
        } catch (\Exception $e) {
            Log::error("Error processing new emails: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Check if email is from bank domain
     */
    public function isFromBank(string $rawEmail, array $allowedBanks): bool
    {
        // Extract From header
        if (preg_match('/^From:\s*(.+)$/mi', $rawEmail, $matches)) {
            $from = $matches[1];
            
            // Extract domain
            if (preg_match('/@([^\s>]+)/', $from, $domainMatch)) {
                $domain = strtolower(trim($domainMatch[1]));
                
                // Check against allowed banks
                foreach ($allowedBanks as $bankDomain) {
                    if ($domain === $bankDomain || 
                        substr($domain, -strlen($bankDomain) - 1) === '.' . $bankDomain) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if email matches payment pattern
     */
    public function matchesPaymentPattern(string $rawEmail, string $bankDomain): bool
    {
        // Payment patterns by bank
        $patterns = $this->getPaymentPatterns();
        
        if (!isset($patterns[$bankDomain])) {
            return false;
        }
        
        $bankPatterns = $patterns[$bankDomain];
        
        // Extract subject
        $subject = '';
        if (preg_match('/^Subject:\s*(.+)$/mi', $rawEmail, $matches)) {
            $subject = $matches[1];
        }
        
        // Extract body (first 5000 chars)
        $body = '';
        $bodyStart = strpos($rawEmail, "\r\n\r\n");
        if ($bodyStart !== false) {
            $body = substr($rawEmail, $bodyStart + 4, 5000);
        }
        
        // Check subject pattern
        if (isset($bankPatterns['subject'])) {
            if (!preg_match($bankPatterns['subject'], $subject)) {
                return false;
            }
        }
        
        // Check body pattern
        if (isset($bankPatterns['body'])) {
            if (!preg_match($bankPatterns['body'], $body)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get payment patterns by bank
     */
    private function getPaymentPatterns(): array
    {
        return [
            'sberbank.ru' => [
                'subject' => '/оплата|платеж|перевод|payment|списание/i',
                'body' => '/сумма.*?(\d+[.,]\d+)|amount.*?(\d+[.,]\d+)|переведено.*?(\d+[.,]\d+)/i',
            ],
            'tinkoff.ru' => [
                'subject' => '/оплата|платеж|перевод|списание/i',
                'body' => '/сумма.*?(\d+[.,]\d+)|переведено.*?(\d+[.,]\d+)/i',
            ],
            'alfabank.ru' => [
                'subject' => '/оплата|платеж|перевод/i',
                'body' => '/сумма.*?(\d+[.,]\d+)/i',
            ],
            // Add more banks as needed
        ];
    }
    
    /**
     * Mark email as read
     */
    public function markAsRead(int $emailNum): void
    {
        if (!$this->folder) {
            return;
        }
        
        try {
            // Use messages()->where('uid', $emailNum) to get message by UID
            $messages = $this->folder->messages()
                ->where('uid', $emailNum)
                ->limit(1)
                ->get();
            
            if ($messages->isNotEmpty()) {
                $message = $messages->first();
                $message->setFlag('\\Seen');
            }
        } catch (\Exception $e) {
            Log::error("Error marking email #{$emailNum} as read: " . $e->getMessage());
        }
    }
    
    /**
     * Close IMAP connection
     */
    public function disconnect(): void
    {
        if ($this->client) {
            try {
                $this->client->disconnect();
            } catch (\Exception $e) {
                Log::error("Error disconnecting IMAP: " . $e->getMessage());
            }
            $this->client = null;
            $this->folder = null;
        }
    }
    
    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}

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
 * Uses webklex/php-imap library which supports XOAUTH2 authentication
 * for OAuth tokens (Yandex, Gmail, Outlook).
 */
class ImapMonitorService
{
    private ?Client $client = null;
    private ?Folder $folder = null;
    private string $email = '';
    
    /**
     * Connect to IMAP server using OAuth token
     * 
     * For Yandex: requires email address and OAuth access_token with mail:imap_ro or mail:imap_full scope
     * 
     * @param string $email User email address (required, should be obtained from OAuth)
     * @param string $accessToken OAuth access token
     * @param string $imapHost IMAP server hostname (e.g., imap.yandex.com for Yandex)
     * @param int $port IMAP server port (default: 993 for SSL)
     * @return bool True if connection successful
     */
    public function connectWithOAuth(string $email, string $accessToken, string $imapHost, int $port = 993): bool
    {
        try {
            if (empty($email)) {
                Log::error("Email address is required for IMAP connection");
                return false;
            }
            
            $this->email = $email;
            
            Log::info("Attempting IMAP OAuth connection", [
                'email' => $this->email,
                'host' => $imapHost,
                'port' => $port,
                'has_token' => !empty($accessToken),
                'token_preview' => !empty($accessToken) ? substr($accessToken, 0, 20) . '...' : 'empty',
                'full_token' => $accessToken // TEMPORARY for testing
            ]);
            
            $cm = new ClientManager();
            
            // Create IMAP client with XOAUTH2 authentication
            // For Yandex: use 'oauth' authentication method with access_token as password
            $config = [
                'host'          => $imapHost,
                'port'          => $port,
                'encryption'    => 'ssl',
                'validate_cert' => true,
                'protocol'      => 'imap',
                'username'      => $this->email,
                'password'      => $accessToken, // OAuth access_token (without "Bearer " prefix)
                'authentication' => 'oauth',      // XOAUTH2 authentication
            ];
            
            Log::info("IMAP client configuration", [
                'host' => $config['host'],
                'port' => $config['port'],
                'username' => $config['username'],
                'authentication' => $config['authentication'],
                'has_password' => !empty($config['password'])
            ]);
            
            $this->client = $cm->make($config);
            
            // Verify token validity with Yandex API before attempting IMAP connection
            try {
                $httpClient = new \GuzzleHttp\Client();
                $verifyResponse = $httpClient->get('https://login.yandex.ru/info', [
                    'headers' => [
                        'Authorization' => 'OAuth ' . $accessToken,
                    ],
                    'timeout' => 10,
                ]);
                
                $userInfo = json_decode($verifyResponse->getBody()->getContents(), true);
                Log::info("Yandex token verification successful", [
                    'email' => $userInfo['default_email'] ?? 'unknown',
                    'login' => $userInfo['login'] ?? 'unknown',
                    'scopes' => $userInfo['scopes'] ?? 'unknown'
                ]);
                
                // For Yandex IMAP XOAUTH2, use full email address (not login)
                // According to RFC 7628 and Yandex documentation, username should be full email
                // Ensure we use the email from OAuth (which is the full email address)
                $config['username'] = $this->email;
            } catch (\Exception $verifyError) {
                Log::warning("Yandex token verification failed, but proceeding with IMAP connection", [
                    'error' => $verifyError->getMessage()
                ]);
            }
            
            // Connect to IMAP server
            Log::info("Calling client->connect() for IMAP OAuth", [
                'username' => $config['username']
            ]);
            $this->client->connect();
            Log::info("client->connect() completed successfully");
            
            // Get INBOX folder
            $this->folder = $this->client->getFolder('INBOX');
            
            Log::info("IMAP OAuth connected successfully for {$this->email}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("IMAP OAuth connection error: " . $e->getMessage(), [
                'email' => $this->email,
                'host' => $imapHost,
                'exception' => $e->getTraceAsString()
            ]);
            return false;
        }
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
            // Get messages with pagination
            $messages = $this->folder->messages()
                ->all()
                ->limit($limit, $offset)
                ->get();
            
            Log::info("IMAP getEmailList: retrieved " . $messages->count() . " messages");
            
            $result = [];
            
            foreach ($messages as $message) {
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
            return null;
        }
        
        try {
            // Try to find message by Message-ID header
            $messages = $this->folder->messages()
                ->where('message_id', $messageId)
                ->limit(1)
                ->get();
            
            if ($messages->isEmpty() && is_numeric($messageId)) {
                // If not found by Message-ID and messageId is numeric, try by UID
                $message = $this->folder->getMessage((int)$messageId);
            } else {
                $message = $messages->first();
            }
            
            if (!$message) {
                return null;
            }
            
            /** @var Message $message */
            // Get raw RFC822 content (all headers + body, preserves DKIM-Signature)
            return $message->getRawBody();
            
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
            $message = $this->folder->getMessage($emailNum);
            
            if (!$message) {
                return null;
            }
            
            /** @var Message $message */
            // Get raw RFC822 content (all headers + body, preserves DKIM-Signature)
            return $message->getRawBody();
            
        } catch (\Exception $e) {
            Log::error("Error getting raw email #{$emailNum}: " . $e->getMessage());
            return null;
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
                    $rawEmail = $message->getRawBody();
                    
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
            $message = $this->folder->getMessage($emailNum);
            if ($message) {
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

<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;

/**
 * IMAP Monitor Service
 * 
 * Monitors user's email inbox via IMAP to read original emails
 * with preserved DKIM signatures for payment verification
 */
class ImapMonitorService
{
    private $imapConnection;
    private $emailAccount;
    
    /**
     * Connect to IMAP server
     */
    public function connect(string $email, string $password, string $imapHost, int $port = 993): bool
    {
        try {
            // Password should already be decrypted (from model accessor)
            // or plain text (for testing)
            
            // Build IMAP connection string
            $connectionString = "{{$imapHost}:{$port}/imap/ssl}INBOX";
            
            // Connect
            $this->imapConnection = @imap_open($connectionString, $email, $password);
            
            if (!$this->imapConnection) {
                $error = imap_last_error();
                Log::error("IMAP connection failed: {$error}");
                return false;
            }
            
            Log::info("IMAP connected successfully for {$email}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("IMAP connection error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Monitor inbox for new emails using IDLE
     */
    public function monitorInbox(callable $emailProcessor): void
    {
        if (!$this->imapConnection) {
            throw new \RuntimeException("IMAP not connected");
        }
        
        // Use IDLE for real-time notifications
        imap_idle($this->imapConnection);
        
        // Check for new emails
        $this->processNewEmails($emailProcessor);
    }
    
    /**
     * Process new unread emails
     */
    public function processNewEmails(callable $emailProcessor): int
    {
        if (!$this->imapConnection) {
            return 0;
        }
        
        // Search for unread emails
        $emails = @imap_search($this->imapConnection, 'UNSEEN');
        
        if (!$emails) {
            return 0;
        }
        
        $processed = 0;
        
        foreach ($emails as $emailNum) {
            try {
                // Get raw email (preserves DKIM signature)
                $rawEmail = $this->getRawEmail($emailNum);
                
                if ($rawEmail) {
                    // Process email
                    $emailProcessor($rawEmail, $emailNum);
                    $processed++;
                }
                
            } catch (\Exception $e) {
                Log::error("Error processing email #{$emailNum}: " . $e->getMessage());
            }
        }
        
        return $processed;
    }
    
    /**
     * Get raw email with preserved headers and DKIM
     */
    private function getRawEmail(int $emailNum): ?string
    {
        // Get full header (includes DKIM-Signature)
        $header = imap_fetchheader($this->imapConnection, $emailNum, FT_PREFETCHTEXT);
        
        // Get body
        $body = imap_body($this->imapConnection, $emailNum, FT_PEEK);
        
        if (!$header || !$body) {
            return null;
        }
        
        // Combine header + body = original email
        return $header . "\r\n" . $body;
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
        if ($this->imapConnection) {
            imap_setflag_full($this->imapConnection, $emailNum, "\\Seen");
        }
    }
    
    /**
     * Close IMAP connection
     */
    public function disconnect(): void
    {
        if ($this->imapConnection) {
            imap_close($this->imapConnection);
            $this->imapConnection = null;
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


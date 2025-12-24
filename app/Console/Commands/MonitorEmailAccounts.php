<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use App\Services\ImapMonitorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorEmailAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:monitor {--once : Run once instead of continuous monitoring}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor email accounts for bank payment emails via IMAP';

    /**
     * Allowed bank domains
     */
    private array $allowedBanks = [
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
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accounts = EmailAccount::active()->get();

        if ($accounts->isEmpty()) {
            $this->info('No active email accounts to monitor.');
            return 0;
        }

        $this->info("Monitoring {$accounts->count()} email account(s)...");

        foreach ($accounts as $account) {
            $this->monitorAccount($account);
        }

        if (!$this->option('once')) {
            // Continuous monitoring with IDLE
            $this->info('Starting continuous monitoring (IDLE mode)...');
            $this->monitorWithIdle($accounts);
        }

        return 0;
    }

    /**
     * Monitor single account
     */
    private function monitorAccount(EmailAccount $account): void
    {
        $this->line("Checking {$account->email}...");

        try {
            $imapService = new ImapMonitorService();
            
            // Connect (use password accessor which decrypts automatically)
            $connected = $imapService->connect(
                $account->email,
                $account->password, // Accessor decrypts automatically
                $account->imap_host,
                $account->imap_port
            );

            if (!$connected) {
                $this->error("Failed to connect to {$account->email}");
                return;
            }

            // Process new emails
            $processed = $imapService->processNewEmails(function ($rawEmail, $emailNum) use ($account, $imapService) {
                $this->processEmail($rawEmail, $emailNum, $account, $imapService);
            });

            $this->info("Processed {$processed} email(s) from {$account->email}");

            // Update last checked
            $account->last_checked_at = now();
            $account->save();

            $imapService->disconnect();

        } catch (\Exception $e) {
            $this->error("Error monitoring {$account->email}: " . $e->getMessage());
            Log::error("Email monitoring error for {$account->email}: " . $e->getMessage());
        }
    }

    /**
     * Process email
     */
    private function processEmail(string $rawEmail, int $emailNum, EmailAccount $account, ImapMonitorService $imapService): void
    {
        // Check if from bank
        if (!$imapService->isFromBank($rawEmail, $this->allowedBanks)) {
            $this->line("  Email #{$emailNum}: Not from bank, skipping");
            return;
        }

        // Extract bank domain
        $bankDomain = $this->extractBankDomain($rawEmail);
        if (!$bankDomain) {
            $this->line("  Email #{$emailNum}: Could not extract bank domain");
            return;
        }

        // Check if matches payment pattern
        if (!$imapService->matchesPaymentPattern($rawEmail, $bankDomain)) {
            $this->line("  Email #{$emailNum}: Does not match payment pattern");
            return;
        }

        $this->info("  Email #{$emailNum}: Payment email detected from {$bankDomain}");

        // Verify DKIM (original email, not forwarded!)
        $dkimResult = $this->verifyDKIM($rawEmail);
        
        if (!$dkimResult['valid']) {
            $this->warn("  Email #{$emailNum}: DKIM verification failed");
            Log::warning("DKIM verification failed for email from {$bankDomain}", [
                'email_account' => $account->email,
                'error' => $dkimResult['error'],
            ]);
            return;
        }

        $this->info("  Email #{$emailNum}: DKIM verified successfully");

        // Extract payment data
        $paymentData = $this->extractPaymentData($rawEmail, $bankDomain);

        // Call API to update transaction
        $this->updateTransaction($paymentData, $rawEmail, $dkimResult, $account);

        // Mark as read
        $imapService->markAsRead($emailNum);
    }

    /**
     * Extract bank domain from email
     */
    private function extractBankDomain(string $rawEmail): ?string
    {
        if (preg_match('/^From:\s*(.+)$/mi', $rawEmail, $matches)) {
            $from = $matches[1];
            if (preg_match('/@([^\s>]+)/', $from, $domainMatch)) {
                $domain = strtolower(trim($domainMatch[1]));
                
                foreach ($this->allowedBanks as $bankDomain) {
                    if ($domain === $bankDomain || 
                        substr($domain, -strlen($bankDomain) - 1) === '.' . $bankDomain) {
                        return $bankDomain;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Verify DKIM signature
     */
    private function verifyDKIM(string $rawEmail): array
    {
        // Use opendkim-testmsg
        $tempFile = tempnam(sys_get_temp_dir(), 'dkim_');
        file_put_contents($tempFile, $rawEmail);
        
        $command = "opendkim-testmsg -f {$tempFile} 2>&1";
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        unlink($tempFile);
        
        if ($returnCode === 0) {
            return ['valid' => true, 'error' => null];
        }
        
        return ['valid' => false, 'error' => implode("\n", $output)];
    }

    /**
     * Extract payment data from email
     */
    private function extractPaymentData(string $rawEmail, string $bankDomain): array
    {
        // Extract amount, date, reference number, etc.
        $data = [
            'bank' => $bankDomain,
            'amount' => null,
            'date' => null,
            'reference' => null,
        ];

        // Extract body
        $bodyStart = strpos($rawEmail, "\r\n\r\n");
        $body = $bodyStart !== false ? substr($rawEmail, $bodyStart + 4, 5000) : '';

        // Extract amount (example pattern)
        if (preg_match('/(?:сумма|amount|переведено).*?(\d+[.,]\d+)/i', $body, $matches)) {
            $data['amount'] = str_replace(',', '.', $matches[1]);
        }

        // Extract date
        if (preg_match('/(\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4})/', $body, $matches)) {
            $data['date'] = $matches[1];
        }

        return $data;
    }

    /**
     * Update transaction via API
     */
    private function updateTransaction(array $paymentData, string $rawEmail, array $dkimResult, EmailAccount $account): void
    {
        // TODO: Call Laravel API endpoint to update transaction
        // For now, just log
        Log::info("Payment email verified", [
            'email_account' => $account->email,
            'bank' => $paymentData['bank'],
            'amount' => $paymentData['amount'],
            'dkim_domain' => $dkimResult['domain'] ?? null,
            'email_hash' => hash('sha256', $rawEmail),
        ]);
    }

    /**
     * Monitor with IDLE (continuous)
     */
    private function monitorWithIdle($accounts): void
    {
        // TODO: Implement IDLE monitoring
        // This requires keeping connections open
        // For now, use cron job to run this command every minute
        $this->info('Use cron job: * * * * * php artisan email:monitor --once');
    }
}


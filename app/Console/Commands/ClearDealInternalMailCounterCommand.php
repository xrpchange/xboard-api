<?php

namespace App\Console\Commands;

use App\Services\Deal\DealInternalMailRateLimitService;
use Illuminate\Console\Command;

class ClearDealInternalMailCounterCommand extends Command
{
    protected $signature = 'deal:internal-mail-counter:clear {email : Lot mailbox email (sender)}';

    protected $description = 'Reset today\'s internal deal mail counter for a lot mailbox (UTC day)';

    public function handle(DealInternalMailRateLimitService $rateLimit): int
    {
        $email = (string) $this->argument('email');
        $normalized = $rateLimit->normalizeMailboxEmail($email);
        if ($normalized === null) {
            $this->error('Invalid mailbox email.');

            return self::FAILURE;
        }

        $deleted = $rateLimit->clearTodayForMailbox($normalized);
        if ($deleted === 0) {
            $this->info("No counter row for {$normalized} on today's UTC day.");

            return self::SUCCESS;
        }

        $this->info("Cleared internal mail counter for {$normalized} (UTC today).");

        return self::SUCCESS;
    }
}

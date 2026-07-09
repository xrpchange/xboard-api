<?php

namespace App\Console\Commands;

use App\Services\Deal\SellerCryptoPayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessDealCryptoPayoutJobsCommand extends Command
{
    protected $signature = 'deal-crypto-payout:process
                            {--limit=20 : Max queued jobs per run}';

    protected $description = 'Retry queued seller crypto payout jobs (XMR maturity / unlocked balance)';

    public function handle(SellerCryptoPayoutService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $processed = $service->processQueuedJobs($limit);
        $this->info("Processed {$processed} deal crypto payout job(s).");
        Log::info('deal-crypto-payout:process completed', ['processed' => $processed, 'limit' => $limit]);

        return self::SUCCESS;
    }
}

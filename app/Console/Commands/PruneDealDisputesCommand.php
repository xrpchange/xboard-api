<?php

namespace App\Console\Commands;

use App\Models\DealDispute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Permanently delete deal_disputes older than 15 days.
 * Run manually: php artisan deal-disputes:prune
 * Scheduled daily via bootstrap/app.php.
 */
class PruneDealDisputesCommand extends Command
{
    protected $signature = 'deal-disputes:prune
                            {--days=15 : Delete disputes older than this many days}
                            {--dry-run : Show count without deleting}';

    protected $description = 'Delete deal_disputes older than 15 days (configurable)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $before = now()->subDays($days);

        $query = DealDispute::where('created_at', '<', $before);
        $count = $query->count();

        if ($count === 0) {
            $this->info("No deal_disputes older than {$days} days.");
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] Would delete {$count} deal_disputes older than {$days} days (before {$before->toIso8601String()}).");
            return self::SUCCESS;
        }

        $query->delete();
        $this->info("Deleted {$count} deal_disputes older than {$days} days.");
        Log::info('deal-disputes:prune completed', ['deleted' => $count, 'older_than_days' => $days]);

        return self::SUCCESS;
    }
}


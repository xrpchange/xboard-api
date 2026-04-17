<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Permanently delete transactions older than 15 days.
 * sold_crypto_amount is accumulated when a sale becomes completed; pruning only removes old rows.
 * Run manually: php artisan transactions:prune
 * Scheduled daily via bootstrap/app.php.
 */
class PruneTransactionsCommand extends Command
{
    protected $signature = 'transactions:prune
                            {--days=15 : Delete records older than this many days}
                            {--dry-run : Show count without deleting}';

    protected $description = 'Delete transactions older than 15 days (configurable)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $before = now()->subDays($days);

        $query = Transaction::where('created_at', '<', $before);
        $count = $query->count();

        if ($count === 0) {
            $this->info("No transactions older than {$days} days.");
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] Would delete {$count} transactions older than {$days} days (before {$before->toIso8601String()}).");
            return self::SUCCESS;
        }

        $query->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                $row->delete();
            }
        });
        $this->info("Deleted {$count} transactions older than {$days} days.");
        Log::info('transactions:prune completed', ['deleted' => $count, 'older_than_days' => $days]);

        return self::SUCCESS;
    }
}

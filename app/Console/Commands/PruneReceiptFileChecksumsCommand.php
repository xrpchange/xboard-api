<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Permanently delete receipt file checksums older than deal chat TTL.
 * Run manually: php artisan receipt-checksums:prune
 * Scheduled daily via bootstrap/app.php.
 */
class PruneReceiptFileChecksumsCommand extends Command
{
    protected $signature = 'receipt-checksums:prune
                            {--days=15 : Delete checksum rows older than this many days}
                            {--dry-run : Show count without deleting}';

    protected $description = 'Delete receipt_file_checksums older than 15 days (configurable)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');
        $before = now()->subDays($days);

        $query = DB::table('receipt_file_checksums')->where('created_at', '<', $before);
        $count = $query->count();
        if ($count === 0) {
            $this->info("No receipt_file_checksums older than {$days} days.");
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] Would delete {$count} receipt_file_checksums older than {$days} days (before {$before->toIso8601String()}).");
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} receipt_file_checksums older than {$days} days.");
        Log::info('receipt-checksums:prune completed', ['deleted' => $deleted, 'older_than_days' => $days]);

        return self::SUCCESS;
    }
}

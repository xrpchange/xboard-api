<?php

namespace App\Console\Commands;

use App\Services\ReceiptAttachmentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Delete email_attachments/{date} folders for dates older than 15 days.
 * Run daily; 15-day delay aligns with transactions:prune so receipt PDFs are cleaned.
 */
class PruneEmailAttachmentsDatesCommand extends Command
{
    protected $signature = 'email-attachments-dates:prune
                            {--days=15 : Remove date folders older than this many days}
                            {--dry-run : List folders that would be deleted only}';

    protected $description = 'Delete email_attachments/{date} folders older than N days (default 15)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($days)->format('Y-m-d');

        if ($dryRun) {
            $this->info("[dry-run] Would delete email_attachments/{date} for date < {$cutoff} (older than {$days} days)");
            return self::SUCCESS;
        }

        $result = ReceiptAttachmentStorage::deleteDateFoldersOlderThanDays($days);
        $deleted = $result['deleted'];
        $errors = $result['errors'];

        if (count($deleted) > 0) {
            $this->info('Deleted ' . count($deleted) . ' date folder(s): ' . implode(', ', $deleted));
        } else {
            $this->info('No date folders older than ' . $days . ' days.');
        }
        foreach ($errors as $err) {
            $this->warn($err);
        }
        Log::info('email-attachments-dates:prune completed', ['deleted_count' => count($deleted), 'days' => $days]);

        return self::SUCCESS;
    }
}

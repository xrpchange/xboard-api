<?php

namespace App\Console\Commands;

use App\Services\ReceiptAttachmentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Delete email_attachments/{date}/tmp for date = 2 days ago (one-day buffer).
 * Run daily so test PDFs (saved without bank_id) are cleaned with a small delay.
 */
class PruneEmailAttachmentsTmpCommand extends Command
{
    protected $signature = 'email-attachments-tmp:prune
                            {--dry-run : Log target date only, do not delete}';

    protected $description = 'Delete email_attachments/{date}/tmp for date two days ago (daily cleanup with 1-day buffer)';

    public function handle(): int
    {
        $date = now()->subDays(2)->format('Y-m-d');

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Would delete directory email_attachments/{$date}/tmp");
            return self::SUCCESS;
        }

        $ok = ReceiptAttachmentStorage::deleteTmpFolderForDate($date);
        if ($ok) {
            $this->info("Pruned email_attachments/{$date}/tmp");
        } else {
            $this->warn("Nothing to prune or failed for email_attachments/{$date}/tmp");
        }
        Log::info('email-attachments-tmp:prune completed', ['date' => $date]);

        return self::SUCCESS;
    }
}

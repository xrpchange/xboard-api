<?php

namespace App\Console\Commands;

use App\Models\DealChatMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Permanently delete deal_chat_messages older than 15 days.
 * Run manually: php artisan deal-chat:prune
 * Scheduled daily via routes/console.php.
 */
class PruneDealChatMessagesCommand extends Command
{
    protected $signature = 'deal-chat:prune
                            {--days=15 : Delete messages older than this many days}
                            {--dry-run : Show count without deleting}';

    protected $description = 'Delete deal_chat_messages older than 15 days (configurable)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $before = now()->subDays($days);

        $query = DealChatMessage::where('created_at', '<', $before);
        $count = $query->count();

        if ($count === 0) {
            $this->info("No deal_chat_messages older than {$days} days.");
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] Would delete {$count} deal_chat_messages older than {$days} days (before {$before->toIso8601String()}).");
            return self::SUCCESS;
        }

        $query->delete();
        $this->info("Deleted {$count} deal_chat_messages older than {$days} days.");
        Log::info('deal-chat:prune completed', ['deleted' => $count, 'older_than_days' => $days]);

        return self::SUCCESS;
    }
}

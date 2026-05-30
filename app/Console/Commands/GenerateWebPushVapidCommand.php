<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

final class GenerateWebPushVapidCommand extends Command
{
    protected $signature = 'web-push:generate-vapid {--show : Print keys only, do not suggest .env lines}';

    protected $description = 'Generate VAPID key pair for Web Push (.env WEB_PUSH_VAPID_*)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $public = $keys['publicKey'] ?? '';
        $private = $keys['privateKey'] ?? '';

        if ($public === '' || $private === '') {
            $this->error('Failed to generate VAPID keys.');

            return self::FAILURE;
        }

        if ($this->option('show')) {
            $this->line('WEB_PUSH_VAPID_PUBLIC_KEY='.$public);
            $this->line('WEB_PUSH_VAPID_PRIVATE_KEY='.$private);

            return self::SUCCESS;
        }

        $this->info('Add to .env (keep private key secret):');
        $this->line('');
        $this->line('WEB_PUSH_VAPID_PUBLIC_KEY='.$public);
        $this->line('WEB_PUSH_VAPID_PRIVATE_KEY='.$private);
        $this->line('WEB_PUSH_VAPID_SUBJECT=mailto:admin@xdexboard.com');
        $this->line('');

        return self::SUCCESS;
    }
}

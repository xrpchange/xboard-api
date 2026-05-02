<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one minimal message using the default mailer (same stack as verification codes).
 * Use when Postfix returns 535: confirms Laravel .env + config vs server SASL.
 */
class MailTestSmtpCommand extends Command
{
    protected $signature = 'mail:test-smtp
                            {--to= : Recipient (default: MAIL_USERNAME so you do not need another inbox)}';

    protected $description = 'Send a one-line test email via configured MAIL_MAILER (same as escrow verification mail)';

    public function handle(): int
    {
        $mailer = (string) config('mail.default', 'smtp');
        $to = $this->option('to') ?: (string) config('mail.mailers.smtp.username', '');
        if ($to === '') {
            $this->error('Set MAIL_USERNAME in .env or pass --to=address@example.com');
            return self::FAILURE;
        }

        $this->line('mail.default = '.$mailer);
        $this->line('smtp.host = '.(string) config('mail.mailers.smtp.host'));
        $this->line('smtp.port = '.(string) config('mail.mailers.smtp.port'));
        $this->line('smtp.encryption = '.json_encode(config('mail.mailers.smtp.encryption')));
        $this->line('smtp.username = '.(string) config('mail.mailers.smtp.username'));
        $this->line('mail.from.address = '.(string) config('mail.from.address'));
        $this->line('Recipient: '.$to);

        try {
            Mail::mailer($mailer)->raw(
                "XBoard SMTP test at ".now()->toIso8601String()." UTC\n",
                function ($message) use ($to) {
                    $message->to($to)
                        ->subject('XBoard mail:test-smtp');
                }
            );
        } catch (\Throwable $e) {
            $this->error('Send failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info('Mail transport accepted the message (no exception). If mailer=log, check storage/logs/laravel.log.');
        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Minimal message to lot mailboxes so deal clients' IMAP IDLE wakes and they refetch chat from the API.
 */
final class DealChatImapWakeMailable extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $toEmail,
    ) {}

    public function build(): self
    {
        return $this->to($this->toEmail)
            ->subject('XBoard deal chat update')
            ->from((string) config('mail.from.address', 'noreply@xdexboard.com'), (string) config('mail.from.name', 'XBoard'))
            ->text('mail.imap_wake')
            ->withSymfonyMessage(function ($symfonyEmail): void {
                HighDeliveryPriority::apply($symfonyEmail);
            });
    }
}

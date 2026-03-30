<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MailboxVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $toEmail
    ) {}

    public function envelope(): Envelope
    {
        $toEmail = $this->toEmail;

        return (new Envelope(
            subject: 'Your code for escrow token',
            to: [$toEmail],
            from: config('mail.from.address'),
            replyTo: [config('mail.from.address')],
        ))->using(function (\Illuminate\Mail\Message $message) {
            HighDeliveryPriority::apply($message);
        });
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.mailbox_verification_code',
        );
    }
}

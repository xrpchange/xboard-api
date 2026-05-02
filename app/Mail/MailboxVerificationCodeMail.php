<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

/**
 * Mailbox one-time verification code. User-facing subject/body fragments may be provided by the client
 * (localized in-app); legacy clients omit optional fields → fixed English fallback in {@see buildPresentation}.
 */
class MailboxVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array<string, string>|null */
    private ?array $presentationCache = null;

    public function __construct(
        public string $code,
        public string $toEmail,
        public ?string $mailSubject = null,
        public ?string $mailHeader = null,
        public ?string $mailIntro = null,
        public ?string $mailAfterCode = null,
        public ?string $mailFooter = null,
    ) {}

    private function normalizeSingleLine(?string $s): string
    {
        if ($s === null || $s === '') {
            return '';
        }
        $oneLine = preg_replace('/[\r\n]+/u', ' ', $s);

        return trim((string) $oneLine);
    }

    /** @return array{subject:string,headerTitle:string,intro:string,afterCode:string,footer:string} */
    private function buildPresentation(): array
    {
        $ttl = (int) config('mail.mailbox_verification.code_ttl_minutes', 30);

        $subjectTrim = trim((string) ($this->mailSubject ?? ''));
        $introTrim = trim((string) ($this->mailIntro ?? ''));
        $clientProvided = $subjectTrim !== '' && $introTrim !== '';

        if ($clientProvided) {
            return [
                'subject' => $this->normalizeSingleLine($subjectTrim) ?: 'XBoard',
                'headerTitle' => trim((string) ($this->mailHeader ?? '')) ?: 'XBoard',
                'intro' => $introTrim,
                'afterCode' => trim((string) ($this->mailAfterCode ?? '')),
                'footer' => trim((string) ($this->mailFooter ?? '')),
            ];
        }

        return [
            'subject' => 'Your code for escrow token',
            'headerTitle' => 'XBoard',
            'intro' => "Your code for escrow token creation.\n\nEnter this code in the app when prompted. The code is valid for {$ttl} minutes.",
            'afterCode' => '',
            'footer' => 'If you did not request this code, ignore this email.',
        ];
    }

    /** @return array{subject:string,headerTitle:string,intro:string,afterCode:string,footer:string} */
    private function presentation(): array
    {
        return $this->presentationCache ??= $this->buildPresentation();
    }

    public function envelope(): Envelope
    {
        $p = $this->presentation();
        $toEmail = $this->toEmail;

        return (new Envelope(
            subject: $p['subject'],
            to: [$toEmail],
            from: config('mail.from.address'),
            replyTo: [config('mail.from.address')],
        ))->using(function (Email $message) {
            HighDeliveryPriority::apply($message);
        });
    }

    public function content(): Content
    {
        $p = $this->presentation();

        return new Content(
            html: 'mail.mailbox_verification_code_layout',
            text: 'mail.mailbox_verification_code_plain',
            with: [
                'subjectForTitle' => $p['subject'],
                'headerTitle' => $p['headerTitle'],
                'intro' => $p['intro'],
                'code' => $this->code,
                'afterCode' => $p['afterCode'],
                'footer' => $p['footer'],
            ]
        );
    }
}

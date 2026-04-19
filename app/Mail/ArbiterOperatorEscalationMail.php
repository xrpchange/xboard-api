<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Internal operator notification — one Blade template: mail.arbiter_operator_escalation.
 */
class ArbiterOperatorEscalationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $operatorEmail,
        public string $lotId,
        /** Plain block from {@see \App\Support\ArbiterDealContextPlainText}; wrapped by the mail template. */
        public string $dealContextPlain,
    ) {}

    public function envelope(): Envelope
    {
        $subject = trim(view('mail.arbiter_operator_escalation', [
            'mailSection' => 'subject',
            'lotId' => $this->lotId,
        ])->render());

        return new Envelope(
            subject: $subject,
            to: [$this->operatorEmail],
            from: config('mail.from.address'),
        );
    }

    public function content(): Content
    {
        $ttlDays = max(1, (int) config('arbiter_operator_mail.action_link_ttl_days', 7));
        $expires = now()->addDays($ttlDays);
        $lot = $this->lotId;

        $urls = [
            'reply' => URL::temporarySignedRoute('arbiter.operator.action', $expires, [
                'lot' => $lot,
                'action' => 'reply',
            ]),
            'compensate_buyer' => URL::temporarySignedRoute('arbiter.operator.action', $expires, [
                'lot' => $lot,
                'action' => 'compensate_buyer',
            ]),
            'deny_buyer' => URL::temporarySignedRoute('arbiter.operator.action', $expires, [
                'lot' => $lot,
                'action' => 'deny_buyer',
            ]),
        ];

        $shared = [
            'lotId' => $lot,
            'urls' => $urls,
            'dealContextPlain' => $this->dealContextPlain,
        ];

        $html = view('mail.arbiter_operator_escalation', array_merge($shared, [
            'mailSection' => 'html',
        ]))->render();

        $plain = view('mail.arbiter_operator_escalation', array_merge($shared, [
            'mailSection' => 'plain',
        ]))->render();

        return new Content(
            htmlString: $html,
            text: trim($plain),
        );
    }
}

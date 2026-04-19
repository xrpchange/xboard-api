<?php

namespace App\Mail;

use App\Support\ArbiterOperatorEscalationDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Internal operator notification — HTML-first human summary in mail.arbiter_operator_escalation.
 */
class ArbiterOperatorEscalationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $digest  from {@see ArbiterOperatorEscalationDigest::build}
     */
    public function __construct(
        public string $operatorEmail,
        public string $lotId,
        public array $digest,
        /** `image_only_receipt` | `policy_manual` */
        public string $mailEscalationKind = 'image_only_receipt',
        /** For `policy_manual`: e.g. insufficient_context */
        public string $policyReasonCode = '',
    ) {}

    public function envelope(): Envelope
    {
        $subject = ArbiterOperatorEscalationDigest::emailSubject(
            $this->lotId,
            $this->mailEscalationKind,
            $this->policyReasonCode,
        );

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

        $html = view('mail.arbiter_operator_escalation', [
            'mailSection' => 'html',
            'lotId' => $lot,
            'urls' => $urls,
            'digest' => $this->digest,
        ])->render();

        $plainTrimmed = ArbiterOperatorEscalationDigest::toPlainText($this->digest, $lot, $urls);

        return new Content(
            htmlString: $html,
            text: 'mail.arbiter_operator_escalation_text',
            with: [
                'arbiterOperatorEscalationPlainBody' => $plainTrimmed,
            ],
        );
    }
}

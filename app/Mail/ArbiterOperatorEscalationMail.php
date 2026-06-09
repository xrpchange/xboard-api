<?php

namespace App\Mail;

use App\Support\ArbiterOperatorEscalationDigest;
use App\Support\PublicHttpsUrl;
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
        $requesterSide = is_string($this->digest['requester_side'] ?? null)
            ? (string) $this->digest['requester_side']
            : null;
        $subject = ArbiterOperatorEscalationDigest::emailSubject(
            $this->lotId,
            $this->mailEscalationKind,
            $this->policyReasonCode,
            $requesterSide,
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

        $base = PublicHttpsUrl::upgradeMailBaseToHttps(self::resolveOperatorMailBaseUrl());
        URL::forceRootUrl($base);
        $digest = $this->digest;
        try {
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
                'block_buyer_internal_mail' => URL::temporarySignedRoute('arbiter.operator.action', $expires, [
                    'lot' => $lot,
                    'action' => 'block_buyer_internal_mail',
                ]),
                'block_seller_internal_mail' => URL::temporarySignedRoute('arbiter.operator.action', $expires, [
                    'lot' => $lot,
                    'action' => 'block_seller_internal_mail',
                ]),
            ];
            $digest = $this->digestWithTxVerifyUrls($digest, $lot, $expires);
        } finally {
            URL::forceRootUrl(null);
        }

        $html = view('mail.arbiter_operator_escalation', [
            'mailSection' => 'html',
            'lotId' => $lot,
            'urls' => $urls,
            'digest' => $digest,
        ])->render();

        $plainTrimmed = ArbiterOperatorEscalationDigest::toPlainText($digest, $lot, $urls);

        return new Content(
            htmlString: $html,
            text: 'mail.arbiter_operator_escalation_text',
            with: [
                'arbiterOperatorEscalationPlainBody' => $plainTrimmed,
            ],
        );
    }

    /**
     * Same origin as the request that triggered the arbiter mail when possible; otherwise standard app.url.
     */
    private static function resolveOperatorMailBaseUrl(): string
    {
        if (! app()->runningInConsole()) {
            try {
                $host = request()->getSchemeAndHttpHost();
                if (is_string($host) && $host !== '') {
                    return rtrim($host, '/');
                }
            } catch (\Throwable) {
                // request() unavailable
            }
        }

        return rtrim((string) config('app.url', 'http://localhost'), '/');
    }

    /**
     * @param  array<string, mixed>  $digest
     * @return array<string, mixed>
     */
    private function digestWithTxVerifyUrls(array $digest, string $lotId, \DateTimeInterface $expires): array
    {
        $proofs = $digest['tx_proofs'] ?? [];
        if (! is_array($proofs) || $proofs === []) {
            return $digest;
        }

        $enriched = [];
        foreach ($proofs as $proof) {
            if (! is_array($proof) || ! empty($proof['bank_receipt_only'])) {
                $enriched[] = $proof;

                continue;
            }
            $messageId = (int) ($proof['message_id'] ?? 0);
            if ($messageId > 0) {
                $proof['verify_url'] = URL::temporarySignedRoute('arbiter.operator.verify_tx', $expires, [
                    'lot' => $lotId,
                    'message' => $messageId,
                ]);
            }
            $enriched[] = $proof;
        }
        $digest['tx_proofs'] = $enriched;

        return $digest;
    }
}

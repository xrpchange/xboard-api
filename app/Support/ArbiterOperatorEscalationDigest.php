<?php

namespace App\Support;

/**
 * Human-readable summary for operator escalation email (HTML + plain).
 * Does not duplicate the LLM debug dump — use {@see ArbiterDealContextPlainText} only for model prompts.
 */
final class ArbiterOperatorEscalationDigest
{
    /** @param  array<string, mixed>  $context */
    /** @param  array<string, mixed>  $rules */
    public static function build(
        array $context,
        array $rules,
        string $mailEscalationKind,
        string $policyReasonCode,
    ): array {
        $confidence = (float) ($rules['confidence'] ?? 0.0);
        $confidencePct = (int) round(max(0.0, min(1.0, $confidence)) * 100);

        $side = strtolower(trim((string) ($context['requester_side'] ?? '')));
        $sideLabel = match ($side) {
            'buyer' => 'Buyer',
            'seller' => 'Seller',
            default => $side !== '' ? ucfirst($side) : 'Unknown',
        };

        $lotId = trim((string) ($context['lot_id'] ?? ''));
        $message = trim((string) ($context['message'] ?? ''));
        $dealState = trim((string) ($context['deal_machine_state'] ?? ''));
        $chatDigest = trim((string) ($context['recent_chat_digest'] ?? ''));
        $rulesDigest = trim((string) ($context['exchange_rules_digest'] ?? ''));
        $receiptText = trim((string) ($context['receipt_pdf_text_excerpt'] ?? ''));
        $pdfSha = trim((string) ($context['pdf_sha256'] ?? ''));
        $emailSha = trim((string) ($context['email_message_sha256'] ?? ''));
        $dkimSha = trim((string) ($context['dkim_signature_sha256'] ?? ''));

        $bankTz = trim((string) ($context['bank_timezone'] ?? ''));
        $payRaw = trim((string) ($context['payment_time_raw'] ?? ''));
        $payUtc = trim((string) ($context['normalized_payment_time'] ?? ''));

        if ($mailEscalationKind === 'image_only_receipt') {
            $headline = 'Bank receipt could not be read automatically';
            $lead = 'A receipt file is on record for this dispute, but it appears to be a scan or image with no extractable text. '
                .'The automatic review step was skipped so a person can review the case.';
            $reasonLabel = 'Scanned or image-only receipt';
        } else {
            $headline = 'Dispute needs manual review';
            $lead = self::policyLead($policyReasonCode);
            $reasonLabel = self::policyReasonLabel($policyReasonCode);
        }

        $facts = [
            ['label' => 'Dispute lot', 'value' => $lotId !== '' ? $lotId : '—'],
            ['label' => 'Opened by', 'value' => $sideLabel],
            [
                'label' => 'Evidence strength (system estimate)',
                'value' => $confidencePct.'% — higher usually means more proof fields and detail were provided.',
            ],
            ['label' => 'Deal stage', 'value' => $dealState !== '' ? $dealState : 'Unknown'],
        ];

        if ($pdfSha !== '') {
            $facts[] = ['label' => 'Receipt file reference', 'value' => $pdfSha];
        }
        if ($emailSha !== '') {
            $facts[] = ['label' => 'Email evidence reference', 'value' => $emailSha];
        }
        if ($dkimSha !== '') {
            $facts[] = ['label' => 'DKIM reference', 'value' => $dkimSha];
        }
        if ($bankTz !== '') {
            $facts[] = ['label' => 'Bank time zone (if given)', 'value' => $bankTz];
        }
        if ($payRaw !== '') {
            $facts[] = ['label' => 'Payment time (as stated)', 'value' => $payRaw];
        }
        if ($payUtc !== '') {
            $facts[] = ['label' => 'Payment time (normalized, UTC)', 'value' => $payUtc];
        }

        $sections = [];
        if ($message !== '') {
            $sections[] = ['title' => 'What the requester wrote', 'content' => $message];
        }
        if ($receiptText !== '') {
            $sections[] = ['title' => 'Text read from the bank receipt', 'content' => $receiptText];
        } elseif ($mailEscalationKind === 'image_only_receipt') {
            $sections[] = [
                'title' => 'Text read from the bank receipt',
                'content' => 'No text could be extracted from the uploaded receipt file.',
            ];
        }
        if ($chatDigest !== '' && $chatDigest !== '(none)') {
            $sections[] = ['title' => 'Recent conversation (summary)', 'content' => $chatDigest];
        }
        if ($rulesDigest !== '' && $rulesDigest !== '(missing)') {
            $sections[] = ['title' => 'Exchange rules (summary for this case)', 'content' => $rulesDigest];
        }

        return [
            'headline' => $headline,
            'lead' => $lead,
            'reason_label' => $reasonLabel,
            'policy_reason_code' => $mailEscalationKind === 'policy_manual' ? trim($policyReasonCode) : '',
            'facts' => $facts,
            'sections' => $sections,
        ];
    }

    public static function emailSubject(string $lotId, string $mailEscalationKind, string $policyReasonCode): string
    {
        $lot = trim($lotId) !== '' ? trim($lotId) : '?';
        if ($mailEscalationKind === 'image_only_receipt') {
            return '[XBoard] Dispute on lot '.$lot.' — receipt needs human review';
        }

        return '[XBoard] Dispute on lot '.$lot.' — '.self::policySubjectFragment($policyReasonCode);
    }

    private static function policySubjectFragment(string $code): string
    {
        return match (trim($code)) {
            'insufficient_context' => 'not enough information for automatic review',
            'anti_replay_triggered' => 'possible duplicate payment evidence',
            'provider_unavailable' => 'review service unavailable',
            'low_confidence' => 'uncertain automatic result',
            default => 'manual review required',
        };
    }

    private static function policyReasonLabel(string $code): string
    {
        return match (trim($code)) {
            'insufficient_context' => 'Not enough information for automatic review',
            'anti_replay_triggered' => 'Possible duplicate payment evidence',
            'provider_unavailable' => 'Review service unavailable',
            'low_confidence' => 'Automatic review was uncertain',
            default => 'Manual review',
        };
    }

    private static function policyLead(string $code): string
    {
        return match (trim($code)) {
            'insufficient_context' => 'There was not enough structured proof or detail for the system to run an automatic review safely. Please review the request and evidence below.',
            'anti_replay_triggered' => 'The system detected a risk that the same payment proof may have been used before. Please verify the evidence carefully.',
            'provider_unavailable' => 'The automated review service could not be reached or failed for every configured provider. Please continue the dispute manually.',
            'low_confidence' => 'An automatic review ran, but the confidence score stayed below the threshold where the platform trusts the result without a person. Please review the output and evidence.',
            default => 'This dispute was escalated for human review. Please use the details below.',
        };
    }

    /**
     * @param  array<string, mixed>  $digest
     * @param  array<string, string>  $urls
     */
    public static function toPlainText(array $digest, string $lotId, array $urls): string
    {
        $lines = [
            $digest['headline'] ?? '',
            '',
            $digest['lead'] ?? '',
            '',
        ];
        if (($digest['reason_label'] ?? '') !== '') {
            $lines[] = 'Reason: '.($digest['reason_label'] ?? '');
            $lines[] = '';
        }
        if (($digest['policy_reason_code'] ?? '') !== '') {
            $lines[] = 'Internal reference: '.($digest['policy_reason_code'] ?? '');
            $lines[] = '';
        }
        $lines[] = 'Key facts';
        $lines[] = str_repeat('-', 40);
        foreach ($digest['facts'] ?? [] as $row) {
            $label = (string) ($row['label'] ?? '');
            $value = (string) ($row['value'] ?? '');
            $lines[] = $label.': '.$value;
        }
        foreach ($digest['sections'] ?? [] as $sec) {
            $lines[] = '';
            $lines[] = (string) ($sec['title'] ?? '');
            $lines[] = str_repeat('-', 40);
            $lines[] = trim((string) ($sec['content'] ?? ''));
        }
        $lines[] = '';
        $lines[] = 'Actions (open in browser; links expire):';
        $lines[] = 'Lot: '.$lotId;
        $lines[] = 'Reply: '.($urls['reply'] ?? '');
        $lines[] = 'Compensate buyer: '.($urls['compensate_buyer'] ?? '');
        $lines[] = 'Deny buyer: '.($urls['deny_buyer'] ?? '');

        return trim(implode("\n", $lines));
    }
}

<?php

namespace App\Support;

/**
 * Same structured plain-text context as sent to the LLM user prompt (without the final "how to respond" instruction lines).
 * Used for operator escalation email bodies and must stay aligned with {@see \App\Services\Arbiter\ArbiterService::userPrompt} data blocks.
 */
final class ArbiterDealContextPlainText
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $rules
     */
    public static function build(array $context, array $rules): string
    {
        $bankTimezone = trim((string) ($context['bank_timezone'] ?? ''));
        $normalizedTime = trim((string) ($context['normalized_payment_time'] ?? ''));
        $originalTime = trim((string) ($context['payment_time_raw'] ?? ''));
        $providerHint = trim((string) ($context['provider_hint'] ?? ''));
        $rulesDigest = (string) ($context['exchange_rules_digest'] ?? '');
        $dealState = trim((string) ($context['deal_machine_state'] ?? ''));
        $dealStateMeaning = trim((string) ($context['deal_machine_state_meaning'] ?? ''));
        $milestonesDigest = trim((string) ($context['deal_milestones_digest'] ?? ''));
        $chatDigest = (string) ($context['recent_chat_digest'] ?? '');
        $receiptText = (string) ($context['receipt_pdf_text_excerpt'] ?? '');

        return implode("\n", [
            'Dispute context:',
            '- requester_side: '.(string) ($context['requester_side'] ?? 'unknown'),
            '- lot_id: '.(string) ($context['lot_id'] ?? ''),
            '- deal_transaction_id: '.(string) ($context['deal_transaction_id'] ?? 'not_set'),
            '- message: '.(string) ($context['message'] ?? ''),
            '- bank_timezone: '.($bankTimezone !== '' ? $bankTimezone : 'not_provided'),
            '- payment_time_raw: '.($originalTime !== '' ? $originalTime : 'not_provided'),
            '- normalized_payment_time_utc: '.($normalizedTime !== '' ? $normalizedTime : 'not_provided'),
            '- pdf_sha256: '.(string) ($context['pdf_sha256'] ?? ''),
            '- email_message_sha256: '.(string) ($context['email_message_sha256'] ?? ''),
            '- dkim_signature_sha256: '.(string) ($context['dkim_signature_sha256'] ?? ''),
            '- receipt_pdf_llm: '.(string) ($context['receipt_pdf_llm'] ?? 'unknown').' (see docs/AI_ARBITER_SERVER.md Receipt PDF)',
            '- deterministic_confidence: '.(string) ($rules['confidence'] ?? 0),
            '- client_provider_hint: '.($providerHint !== '' ? $providerHint : 'none').' (informational only; server selects provider)',
            '',
            'receipt_pdf_text_excerpt (server-side PDF text extraction; whitespace-normalized; truncated):',
            $receiptText !== '' ? $receiptText : '(none)',
            '',
            'deal_machine_state (server-computed from deal_tag history; same reducer as API validators):',
            $dealState !== '' ? $dealState : 'unknown',
            '',
            'deal_machine_state_meaning (authoritative interpretation of the state code):',
            $dealStateMeaning !== '' ? $dealStateMeaning : '(none)',
            '',
            'deal_milestones_digest (chronological key deal_tag milestones; use before inferring from chat prose alone):',
            $milestonesDigest !== '' ? $milestonesDigest : '(none)',
            '',
            'exchange_rules_digest (canonical guidance; if conflict with server behavior, defer to server — say so in prose):',
            $rulesDigest !== '' ? $rulesDigest : '(missing)',
            '',
            'recent_chat_digest (requester-visible thread; truncated):',
            $chatDigest !== '' ? $chatDigest : '(none)',
            '',
            'declared_transactions_digest (buyer_crypto_tx / seller_transfer_tx / seller payout tags; includes core_tx_check when present):',
            trim((string) ($context['declared_transactions_digest'] ?? '')) !== ''
                ? (string) $context['declared_transactions_digest']
                : '(none)',
        ]);
    }
}

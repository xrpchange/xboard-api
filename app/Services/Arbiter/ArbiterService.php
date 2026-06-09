<?php

namespace App\Services\Arbiter;

use App\Services\Arbiter\Contracts\ArbiterProvider;
use App\Support\ArbiterDealContextPlainText;
use App\Support\ReceiptPdfTextExtractor;
use Illuminate\Support\Facades\Log;

class ArbiterService
{
    /** @var array<string, ArbiterProvider> */
    private array $providersById = [];

    /**
     * @param  iterable<ArbiterProvider>  $providers
     */
    public function __construct(
        iterable $providers,
        private readonly string $primaryProvider,
        private readonly float $noAiBelow,
        private readonly float $manualReviewThreshold
    ) {
        foreach ($providers as $provider) {
            $this->providersById[$provider->providerId()] = $provider;
        }
    }

    /**
     * Rules-first: deterministic checks, then AI for the "gray zone" via the **sole** configured primary provider, then operator escalation when needed.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildArbiterMessage(array $context): array
    {
        $rules = $this->evaluateDeterministicContext($context);
        $confidence = (float) ($rules['confidence'] ?? 0.0);

        if (($rules['has_replay'] ?? false) === true) {
            ArbiterOperatorEscalationNotifier::notifyPolicyManualReview('anti_replay_triggered', $context, $rules);

            return $this->manualOnlyOutcome(
                reasonCode: 'anti_replay_triggered',
                confidence: $confidence,
                policy: 'rules_first',
                verdict: 'manual_review'
            );
        }

        if ($confidence <= $this->noAiBelow) {
            ArbiterOperatorEscalationNotifier::notifyPolicyManualReview('insufficient_context', $context, $rules);

            return $this->manualOnlyOutcome(
                reasonCode: 'insufficient_context',
                confidence: $confidence,
                policy: 'rules_first',
                verdict: 'manual_review'
            );
        }

        $receiptAttachment = $this->loadReceiptPdfAttachment($context);
        if (($receiptAttachment['prompt_status'] ?? '') === 'receipt_pdf_image_only_operator_escalation') {
            ArbiterOperatorEscalationNotifier::notifyImageOnlyReceipt($context, $rules);

            return $this->manualOnlyOutcome(
                reasonCode: 'receipt_pdf_image_only_operator_notified',
                confidence: $confidence,
                policy: 'receipt_image_only_operator',
                verdict: 'manual_review'
            );
        }

        $order = $this->resolveProviderOrder();
        $lastError = null;
        foreach ($order as $providerId) {
            $provider = $this->providersById[$providerId] ?? null;
            if ($provider === null) {
                Log::debug('[arbiter] provider_not_registered', ['provider' => $providerId]);

                continue;
            }

            try {
                $ctx = $context;
                $ctx['receipt_pdf_llm'] = $receiptAttachment['prompt_status'];
                $ctx['receipt_pdf_text_excerpt'] = $receiptAttachment['text_excerpt'];
                $generated = $provider->generate([
                    'system_prompt' => $this->systemPrompt(
                        $receiptAttachment['has_binary'],
                        ($receiptAttachment['text_excerpt'] ?? '') !== ''
                    ),
                    'user_prompt' => $this->userPrompt($ctx, $rules),
                    'receipt_pdf_base64' => $receiptAttachment['base64'],
                ]);
                $raw = trim((string) ($generated['raw_text'] ?? ''));
                if ($raw === '') {
                    throw new \RuntimeException('Provider returned empty output.');
                }
                $split = ArbiterResponseParser::splitDisplayAndVerdict($raw);
                $display = trim((string) ($split['display'] ?? ''));
                $parsedVerdict = (string) ($split['verdict'] ?? 'continue_review');
                if ($display === '') {
                    throw new \RuntimeException('Provider returned empty output.');
                }

                if ($confidence < $this->manualReviewThreshold) {
                    $ctxLow = $context;
                    $ctxLow['receipt_pdf_llm'] = $receiptAttachment['prompt_status'];
                    $ctxLow['receipt_pdf_text_excerpt'] = $receiptAttachment['text_excerpt'];
                    $operatorEscalationSent = ArbiterOperatorEscalationNotifier::notifyPolicyManualReview('low_confidence', $ctxLow, $rules);

                    return [
                        'message' => $display."\n\n".'Escalated to manual review due to low confidence in submitted context.',
                        'arbiter_status' => 'manual_review',
                        'confidence' => $confidence,
                        'provider_used' => $provider->providerId(),
                        'model_version' => (string) ($generated['model'] ?? ''),
                        'reason_code' => 'low_confidence',
                        'policy' => 'rules_first_ai_gray_zone',
                        'verdict' => 'manual_review',
                        'operator_escalation_email_sent' => $operatorEscalationSent,
                    ];
                }

                $operatorEscalationSent = null;
                $reasonCode = 'ai_generated';
                if ($parsedVerdict === 'manual_review') {
                    $ctxEsc = $context;
                    $ctxEsc['receipt_pdf_llm'] = $receiptAttachment['prompt_status'];
                    $ctxEsc['receipt_pdf_text_excerpt'] = $receiptAttachment['text_excerpt'];
                    $operatorEscalationSent = ArbiterOperatorEscalationNotifier::notifyPolicyManualReview('ai_inconclusive', $ctxEsc, $rules);
                    $reasonCode = 'ai_inconclusive';
                } elseif ($parsedVerdict === 'continue_review') {
                    $ctxEsc = $context;
                    $ctxEsc['receipt_pdf_llm'] = $receiptAttachment['prompt_status'];
                    $ctxEsc['receipt_pdf_text_excerpt'] = $receiptAttachment['text_excerpt'];
                    $operatorEscalationSent = ArbiterOperatorEscalationNotifier::notifyPolicyManualReview('ai_continue_review', $ctxEsc, $rules);
                }

                return [
                    'message' => $display,
                    'arbiter_status' => $parsedVerdict === 'manual_review' ? 'manual_review' : 'in_review',
                    'confidence' => $confidence,
                    'provider_used' => $provider->providerId(),
                    'model_version' => (string) ($generated['model'] ?? ''),
                    'reason_code' => $reasonCode,
                    'policy' => 'rules_first_ai_gray_zone',
                    'verdict' => $parsedVerdict,
                    'operator_escalation_email_sent' => $operatorEscalationSent,
                ];
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('[arbiter] provider_failed', [
                    'provider' => $providerId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::error('[arbiter] primary_provider_failed', [
            'primary' => $this->primaryProvider,
            'last_error' => $lastError?->getMessage(),
        ]);

        $ctxFail = $context;
        $ctxFail['receipt_pdf_llm'] = $receiptAttachment['prompt_status'];
        $ctxFail['receipt_pdf_text_excerpt'] = $receiptAttachment['text_excerpt'];
        $operatorEscalationSent = ArbiterOperatorEscalationNotifier::notifyPolicyManualReview('provider_unavailable', $ctxFail, $rules);

        return $this->manualOnlyOutcome(
            reasonCode: 'provider_unavailable',
            confidence: $confidence,
            policy: 'rules_first_single_provider',
            verdict: 'manual_review',
            operatorEscalationEmailSent: $operatorEscalationSent
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function evaluateDeterministicContext(array $context): array
    {
        $message = trim((string) ($context['message'] ?? ''));
        $hasEvidence = false;
        foreach (['pdf_sha256', 'email_message_sha256', 'dkim_signature_sha256'] as $field) {
            if (trim((string) ($context[$field] ?? '')) !== '') {
                $hasEvidence = true;
                break;
            }
        }

        $hasReplay = (bool) ($context['has_replay'] ?? false);
        $chatDigest = trim((string) ($context['recent_chat_digest'] ?? ''));
        $hasServerChatContext = $chatDigest !== ''
            && $chatDigest !== '(no chat messages in requester view)';
        $confidence = 0.65;
        if (! $hasEvidence) {
            $confidence -= 0.2;
        }
        // Short *client* message alone is weak; server-assembled chat thread still gives the LLM enough to work with.
        if (mb_strlen($message) < 40 && ! $hasServerChatContext) {
            $confidence -= 0.1;
        }
        if (trim((string) ($context['dkim_signature_sha256'] ?? '')) !== '') {
            $confidence += 0.1;
        }
        if ($hasReplay) {
            $confidence -= 0.35;
        }
        // Requester-visible chat assembled on the server: compensates missing client evidence hashes for score gates.
        if (! $hasEvidence && $hasServerChatContext) {
            $confidence += 0.12;
        }

        $confidence = max(0.0, min(1.0, $confidence));

        return [
            'has_replay' => $hasReplay,
            'confidence' => $confidence,
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveProviderOrder(): array
    {
        $primary = trim($this->primaryProvider);

        return $primary !== '' ? [$primary] : [];
    }

    private function systemPrompt(bool $receiptPdfBinaryAttached, bool $receiptPdfTextExcerptPresent): string
    {
        $base = 'You are an AI arbiter assistant for exchange disputes on this platform. '
            .'Be neutral and concise. Use facts only from the user prompt (rules digest, deal_machine_state, deal_machine_state_meaning, deal_milestones_digest, declared_transactions_digest, recent_chat_digest, fingerprints, requester message). '
            .'Treat deal_milestones_digest and deal_machine_state_meaning as authoritative for whether steps like seller_received_funds or receipt_confirmation occurred — do not contradict them from chat prose alone. '
            .'The exchange server enforces deal_tag order and payouts; your verdict must stay consistent with that digest and state — you cannot override server rules. '
            .'If data is insufficient or contradictory, ask specific questions and prefer continue_review or manual_review over favor_buyer or favor_seller. '
            .'After your human-readable summary (4–8 lines), output exactly one more line with no leading spaces: '
            .ArbiterResponseParser::LINE_PREFIX
            .'{"verdict":"manual_review"|"favor_buyer"|"favor_seller"|"continue_review"}. '
            .'Use favor_buyer when evidence shows the buyer met obligations and crypto should go to the buyer address on file; '
            .'favor_seller when the seller should keep the escrow outcome; manual_review when unsure or more evidence is needed; '
            .'continue_review when more discussion is needed but not escalated to full manual.';
        if ($receiptPdfBinaryAttached || $receiptPdfTextExcerptPresent) {
            $base .= ' Bank receipt evidence: ';
            if ($receiptPdfBinaryAttached) {
                $base .= 'the PDF is also attached as a separate file for this API request where supported; ';
            }
            if ($receiptPdfTextExcerptPresent) {
                $base .= 'plain text extracted from that PDF is under receipt_pdf_text_excerpt (all providers); ';
            }
            $base .= 'align with pdf_sha256; do not invent payment facts absent from the receipt or chat.';
        }

        return $base;
    }

    /**
     * @return array{base64: string|null, text_excerpt: string, prompt_status: string, has_binary: bool}
     */
    private function loadReceiptPdfAttachment(array $context): array
    {
        $empty = [
            'base64' => null,
            'text_excerpt' => '',
            'prompt_status' => 'no_pdf_fingerprint',
            'has_binary' => false,
        ];

        $path = $context['receipt_pdf_absolute_path'] ?? null;
        $maxBytes = $this->receiptPdfMaxBytes();
        $fingerprint = trim((string) ($context['pdf_sha256'] ?? ''));

        if ($fingerprint === '') {
            $empty['prompt_status'] = 'no_pdf_fingerprint';

            return $empty;
        }

        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            $empty['prompt_status'] = 'fingerprint_but_receipt_file_missing_or_unreadable';

            return $empty;
        }

        $size = filesize($path);
        if ($size === false || $size > $maxBytes) {
            $empty['prompt_status'] = 'receipt_pdf_exceeds_max_bytes';

            return $empty;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $empty['prompt_status'] = 'receipt_pdf_read_failed';

            return $empty;
        }

        $textExcerpt = ReceiptPdfTextExtractor::tryExcerptFromPdfBytes($raw, $this->receiptPdfTextExcerptMaxChars());
        if ($textExcerpt === '') {
            return [
                'base64' => null,
                'text_excerpt' => '',
                'prompt_status' => 'receipt_pdf_image_only_operator_escalation',
                'has_binary' => false,
            ];
        }

        return [
            'base64' => base64_encode($raw),
            'text_excerpt' => $textExcerpt,
            'prompt_status' => 'binary_to_anthropic_openai_plus_text_excerpt_for_all',
            'has_binary' => true,
        ];
    }

    private function receiptPdfTextExcerptMaxChars(): int
    {
        $default = 8000;
        if (function_exists('app')) {
            try {
                $app = app();
                if ($app instanceof \Illuminate\Contracts\Foundation\Application && $app->bound('config')) {
                    return (int) config('services.arbiter.receipt_pdf_text_excerpt_max_chars', $default);
                }
            } catch (\Throwable) {
                // PHPUnit without full app container
            }
        }
        $env = getenv('ARBITER_RECEIPT_PDF_TEXT_EXCERPT_MAX_CHARS');
        if (is_string($env) && $env !== '' && ctype_digit($env)) {
            return (int) $env;
        }

        return $default;
    }

    private function receiptPdfMaxBytes(): int
    {
        $default = 5242880;
        if (function_exists('app')) {
            try {
                $app = app();
                if ($app instanceof \Illuminate\Contracts\Foundation\Application && $app->bound('config')) {
                    return (int) config('services.arbiter.receipt_pdf_max_bytes', $default);
                }
            } catch (\Throwable) {
                // PHPUnit without full app container
            }
        }
        $env = getenv('ARBITER_RECEIPT_PDF_MAX_BYTES');
        if (is_string($env) && $env !== '' && ctype_digit($env)) {
            return (int) $env;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $rules
     */
    private function userPrompt(array $context, array $rules): string
    {
        return ArbiterDealContextPlainText::build($context, $rules)."\n\n"
            .'Respond in plain text for the dispute chat (4–8 lines), then the required final line starting with '
            .ArbiterResponseParser::LINE_PREFIX.' and compact JSON only.';
    }

    /**
     * @return array<string, mixed>
     */
    private function manualOnlyOutcome(
        string $reasonCode,
        float $confidence,
        string $policy,
        string $verdict = 'manual_review',
        ?bool $operatorEscalationEmailSent = null
    ): array {
        $out = [
            'message' => $this->manualReviewText($reasonCode, $operatorEscalationEmailSent),
            'arbiter_status' => 'manual_review',
            'confidence' => $confidence,
            'provider_used' => null,
            'model_version' => null,
            'reason_code' => $reasonCode,
            'policy' => $policy,
            'verdict' => $verdict,
        ];
        if ($operatorEscalationEmailSent !== null) {
            $out['operator_escalation_email_sent'] = $operatorEscalationEmailSent;
        }

        return $out;
    }

    private function manualReviewText(string $reasonCode, ?bool $operatorEscalationEmailSent = null): string
    {
        return match ($reasonCode) {
            'anti_replay_triggered' => 'Potential replayed evidence was detected. The dispute is escalated to manual review.',
            'provider_unavailable' => $operatorEscalationEmailSent === true
                ? 'AI providers could not be reached. An exchange operator has been notified by email with this dispute context (signed action links). They can continue the case outside the AI path.'
                : 'AI providers could not be reached. The dispute is escalated to manual review.',
            'insufficient_context' => 'Submitted context is insufficient for AI-assisted review. The dispute is escalated to manual review.',
            'receipt_pdf_image_only_operator_notified' => 'The bank receipt PDF has no extractable text (likely a scanned image). The dispute is escalated to manual review; an operator has been notified by email with full deal context.',
            default => 'The dispute is escalated to manual review.',
        };
    }
}

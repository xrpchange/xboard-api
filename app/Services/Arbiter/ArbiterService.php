<?php

namespace App\Services\Arbiter;

use App\Services\Arbiter\Contracts\ArbiterProvider;
use Illuminate\Support\Facades\Log;

class ArbiterService
{
    /** @var array<string, ArbiterProvider> */
    private array $providersById = [];

    /**
     * @param iterable<ArbiterProvider> $providers
     */
    public function __construct(
        iterable $providers,
        private readonly string $primaryProvider,
        private readonly array $fallbackProviders,
        private readonly float $noAiBelow,
        private readonly float $manualReviewThreshold
    ) {
        foreach ($providers as $provider) {
            $this->providersById[$provider->providerId()] = $provider;
        }
    }

    /**
     * Rules-first: deterministic checks, then AI for the "gray zone", then optional escalation.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function buildArbiterMessage(array $context): array
    {
        $rules = $this->evaluateDeterministicContext($context);
        $confidence = (float) ($rules['confidence'] ?? 0.0);

        if (($rules['has_replay'] ?? false) === true) {
            return $this->manualOnlyOutcome(
                reasonCode: 'anti_replay_triggered',
                confidence: $confidence,
                policy: 'rules_first',
                verdict: 'manual_review'
            );
        }

        if ($confidence <= $this->noAiBelow) {
            return $this->manualOnlyOutcome(
                reasonCode: 'insufficient_context',
                confidence: $confidence,
                policy: 'rules_first',
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
                $generated = $provider->generate([
                    'system_prompt' => $this->systemPrompt(),
                    'user_prompt' => $this->userPrompt($context, $rules),
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
                    return [
                        'message' => $display . "\n\n" . 'Escalated to manual review due to low confidence in submitted context.',
                        'arbiter_status' => 'manual_review',
                        'confidence' => $confidence,
                        'provider_used' => $provider->providerId(),
                        'model_version' => (string) ($generated['model'] ?? ''),
                        'reason_code' => 'low_confidence',
                        'policy' => 'rules_first_ai_gray_zone',
                        'verdict' => 'manual_review',
                    ];
                }

                return [
                    'message' => $display,
                    'arbiter_status' => 'in_review',
                    'confidence' => $confidence,
                    'provider_used' => $provider->providerId(),
                    'model_version' => (string) ($generated['model'] ?? ''),
                    'reason_code' => 'ai_generated',
                    'policy' => 'rules_first_ai_gray_zone',
                    'verdict' => $parsedVerdict,
                ];
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('[arbiter] provider_failed', [
                    'provider' => $providerId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::error('[arbiter] all_providers_failed', [
            'primary' => $this->primaryProvider,
            'fallback' => $this->fallbackProviders,
            'last_error' => $lastError?->getMessage(),
        ]);

        return $this->manualOnlyOutcome(
            reasonCode: 'provider_unavailable',
            confidence: $confidence,
            policy: 'rules_first_provider_failover',
            verdict: 'manual_review'
        );
    }

    /**
     * @param array<string, mixed> $context
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
        $confidence = 0.65;
        if (!$hasEvidence) {
            $confidence -= 0.2;
        }
        if (mb_strlen($message) < 40) {
            $confidence -= 0.1;
        }
        if (trim((string) ($context['dkim_signature_sha256'] ?? '')) !== '') {
            $confidence += 0.1;
        }
        if ($hasReplay) {
            $confidence -= 0.35;
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
        $order = array_merge([$this->primaryProvider], $this->fallbackProviders);

        return array_values(array_unique(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            $order
        ))));
    }

    private function systemPrompt(): string
    {
        return 'You are an AI arbiter assistant for exchange disputes. '
            . 'Be neutral and concise. Use facts only. '
            . 'If data is insufficient, explicitly ask for missing evidence and mention manual review may be required. '
            . 'After your human-readable summary (4–8 lines), output exactly one more line with no leading spaces: '
            . ArbiterResponseParser::LINE_PREFIX
            . '{"verdict":"manual_review"|"favor_buyer"|"favor_seller"|"continue_review"}. '
            . 'Use favor_buyer when evidence shows the buyer met obligations and crypto should go to the buyer address on file; '
            . 'favor_seller when the seller should keep the escrow outcome; manual_review when unsure or more evidence is needed; '
            . 'continue_review when more discussion is needed but not escalated to full manual.';
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $rules
     */
    private function userPrompt(array $context, array $rules): string
    {
        $bankTimezone = trim((string) ($context['bank_timezone'] ?? ''));
        $normalizedTime = trim((string) ($context['normalized_payment_time'] ?? ''));
        $originalTime = trim((string) ($context['payment_time_raw'] ?? ''));
        $providerHint = trim((string) ($context['provider_hint'] ?? ''));

        return implode("\n", [
            'Dispute context:',
            '- requester_side: ' . (string) ($context['requester_side'] ?? 'unknown'),
            '- lot_id: ' . (string) ($context['lot_id'] ?? ''),
            '- message: ' . (string) ($context['message'] ?? ''),
            '- bank_timezone: ' . ($bankTimezone !== '' ? $bankTimezone : 'not_provided'),
            '- payment_time_raw: ' . ($originalTime !== '' ? $originalTime : 'not_provided'),
            '- normalized_payment_time_utc: ' . ($normalizedTime !== '' ? $normalizedTime : 'not_provided'),
            '- pdf_sha256: ' . (string) ($context['pdf_sha256'] ?? ''),
            '- email_message_sha256: ' . (string) ($context['email_message_sha256'] ?? ''),
            '- dkim_signature_sha256: ' . (string) ($context['dkim_signature_sha256'] ?? ''),
            '- deterministic_confidence: ' . (string) ($rules['confidence'] ?? 0),
            '- client_provider_hint: ' . ($providerHint !== '' ? $providerHint : 'none') . ' (informational only; server selects provider)',
            '',
            'Respond in plain text for the dispute chat (4–8 lines), then the required final line starting with '
                . ArbiterResponseParser::LINE_PREFIX . ' and compact JSON only.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function manualOnlyOutcome(string $reasonCode, float $confidence, string $policy, string $verdict = 'manual_review'): array
    {
        return [
            'message' => $this->manualReviewText($reasonCode),
            'arbiter_status' => 'manual_review',
            'confidence' => $confidence,
            'provider_used' => null,
            'model_version' => null,
            'reason_code' => $reasonCode,
            'policy' => $policy,
            'verdict' => $verdict,
        ];
    }

    private function manualReviewText(string $reasonCode): string
    {
        return match ($reasonCode) {
            'anti_replay_triggered' => 'Potential replayed evidence was detected. The dispute is escalated to manual review.',
            'provider_unavailable' => 'AI provider is temporarily unavailable. The dispute is escalated to manual review.',
            'insufficient_context' => 'Submitted context is insufficient for AI-assisted review. The dispute is escalated to manual review.',
            default => 'The dispute is escalated to manual review.',
        };
    }
}

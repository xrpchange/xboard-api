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
                policy: 'rules_first'
            );
        }

        if ($confidence <= $this->noAiBelow) {
            return $this->manualOnlyOutcome(
                reasonCode: 'insufficient_context',
                confidence: $confidence,
                policy: 'rules_first'
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
                $text = trim((string) ($generated['raw_text'] ?? ''));
                if ($text === '') {
                    throw new \RuntimeException('Provider returned empty output.');
                }

                if ($confidence < $this->manualReviewThreshold) {
                    return [
                        'message' => $text . "\n\n" . 'Escalated to manual review due to low confidence in submitted context.',
                        'arbiter_status' => 'manual_review',
                        'confidence' => $confidence,
                        'provider_used' => $provider->providerId(),
                        'model_version' => (string) ($generated['model'] ?? ''),
                        'reason_code' => 'low_confidence',
                        'policy' => 'rules_first_ai_gray_zone',
                    ];
                }

                return [
                    'message' => $text,
                    'arbiter_status' => 'in_review',
                    'confidence' => $confidence,
                    'provider_used' => $provider->providerId(),
                    'model_version' => (string) ($generated['model'] ?? ''),
                    'reason_code' => 'ai_generated',
                    'policy' => 'rules_first_ai_gray_zone',
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
            policy: 'rules_first_provider_failover'
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
            . 'If data is insufficient, explicitly ask for missing evidence and mention manual review may be required.';
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
            'Respond in plain text for the dispute chat. 4-8 lines max.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function manualOnlyOutcome(string $reasonCode, float $confidence, string $policy): array
    {
        return [
            'message' => $this->manualReviewText($reasonCode),
            'arbiter_status' => 'manual_review',
            'confidence' => $confidence,
            'provider_used' => null,
            'model_version' => null,
            'reason_code' => $reasonCode,
            'policy' => $policy,
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

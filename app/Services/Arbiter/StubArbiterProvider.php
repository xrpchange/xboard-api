<?php

namespace App\Services\Arbiter;

use App\Services\Arbiter\Contracts\ArbiterProvider;

/**
 * Deterministic LLM stand-in for CI and local testing (no external API calls).
 * Enable with ARBITER_PRIMARY_PROVIDER=stub in .env or phpunit.xml.
 */
class StubArbiterProvider implements ArbiterProvider
{
    public function providerId(): string
    {
        return 'stub';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload): array
    {
        return [
            'raw_text' => '[stub] Arbiter reply for automated tests. No external LLM was called.',
            'provider' => $this->providerId(),
            'model' => 'stub-v1',
        ];
    }
}

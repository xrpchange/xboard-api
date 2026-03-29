<?php

namespace Tests\Feature;

use App\Services\Arbiter\ArbiterService;
use App\Services\Arbiter\Contracts\ArbiterProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * @see docs/SPEC_REGISTRY.md ARB-003
 */
#[Group('spec')]
#[Group('spec-ARB-003')]
class ArbiterServiceBindingsTest extends TestCase
{
    public function test_spec_arb_003_container_uses_stub_under_phpunit_env(): void
    {
        $svc = $this->app->make(ArbiterService::class);
        $this->assertInstanceOf(ArbiterService::class, $svc);

        $out = $svc->buildArbiterMessage([
            'lot_id' => 1,
            'requester_side' => 'buyer',
            'message' => str_repeat('b', 50),
            'pdf_sha256' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'has_replay' => false,
        ]);

        $this->assertSame('stub', $out['provider_used']);
        $this->assertSame('in_review', $out['arbiter_status']);
    }

    /** @see docs/SPEC_REGISTRY.md ARB-006 (needs Laravel Log facade) */
    #[Group('spec-ARB-006')]
    public function test_spec_arb_006_all_providers_fail(): void
    {
        $failing = new class implements ArbiterProvider {
            public function providerId(): string
            {
                return 'always-fails';
            }

            public function generate(array $payload): array
            {
                throw new \RuntimeException('simulated provider failure');
            }
        };

        $svc = new ArbiterService(
            [$failing],
            'always-fails',
            [],
            0.35,
            0.55,
        );

        $out = $svc->buildArbiterMessage([
            'lot_id' => 1,
            'requester_side' => 'buyer',
            'message' => str_repeat('a', 50),
            'pdf_sha256' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'has_replay' => false,
        ]);

        $this->assertSame('manual_review', $out['arbiter_status']);
        $this->assertNull($out['provider_used']);
        $this->assertSame('provider_unavailable', $out['reason_code']);
        $this->assertStringContainsString('unavailable', strtolower((string) $out['message']));
    }
}

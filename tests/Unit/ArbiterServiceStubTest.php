<?php

namespace Tests\Unit;

use App\Services\Arbiter\ArbiterService;
use App\Services\Arbiter\StubArbiterProvider;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Maps to docs/SPEC_REGISTRY.md (ARB-001 … ARB-006).
 */
#[Group('spec')]
class ArbiterServiceStubTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** @see SPEC_REGISTRY ARB-001 */
    #[Group('spec-ARB-001')]
    public function test_spec_arb_001_sufficient_context_ai_path(): void
    {
        $svc = new ArbiterService(
            providers: [new StubArbiterProvider],
            primaryProvider: 'stub',
            noAiBelow: 0.35,
            manualReviewThreshold: 0.55,
        );
        $out = $svc->buildArbiterMessage([
            'lot_id' => 1,
            'requester_side' => 'buyer',
            'message' => str_repeat('a', 50),
            'pdf_sha256' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'has_replay' => false,
        ]);
        $this->assertSame('in_review', $out['arbiter_status']);
        $this->assertSame('stub', $out['provider_used']);
        $this->assertSame('ai_generated', $out['reason_code']);
        $this->assertStringContainsString('[stub]', (string) $out['message']);
    }

    /** @see SPEC_REGISTRY ARB-002 */
    #[Group('spec-ARB-002')]
    public function test_spec_arb_002_anti_replay(): void
    {
        $svc = new ArbiterService(
            [new StubArbiterProvider],
            'stub',
            0.35,
            0.55,
        );
        $out = $svc->buildArbiterMessage([
            'lot_id' => 1,
            'requester_side' => 'buyer',
            'message' => str_repeat('a', 50),
            'pdf_sha256' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'has_replay' => true,
        ]);
        $this->assertSame('manual_review', $out['arbiter_status']);
        $this->assertNull($out['provider_used']);
        $this->assertSame('anti_replay_triggered', $out['reason_code']);
    }

    /** @see SPEC_REGISTRY ARB-004 */
    #[Group('spec-ARB-004')]
    public function test_spec_arb_004_insufficient_context(): void
    {
        $svc = new ArbiterService(
            [new StubArbiterProvider],
            'stub',
            0.35,
            0.55,
        );
        $out = $svc->buildArbiterMessage([
            'lot_id' => 1,
            'requester_side' => 'buyer',
            'message' => 'short',
            'has_replay' => false,
        ]);
        $this->assertSame('manual_review', $out['arbiter_status']);
        $this->assertNull($out['provider_used']);
        $this->assertSame('insufficient_context', $out['reason_code']);
        $this->assertStringContainsString('insufficient', strtolower((string) $out['message']));
    }

    /** @see SPEC_REGISTRY ARB-013 */
    #[Group('spec-ARB-013')]
    public function test_spec_arb_013_short_client_message_with_server_chat_digest_still_invokes_llm(): void
    {
        $svc = new ArbiterService(
            [new StubArbiterProvider],
            'stub',
            0.35,
            0.55,
        );
        $out = $svc->buildArbiterMessage([
            'lot_id' => 1,
            'requester_side' => 'buyer',
            'message' => 'short',
            'has_replay' => false,
            'recent_chat_digest' => 'id=1 side=buyer tag=(none) body='.str_repeat('x', 120),
        ]);
        $this->assertSame('in_review', $out['arbiter_status']);
        $this->assertSame('stub', $out['provider_used']);
        $this->assertSame('ai_generated', $out['reason_code']);
    }

    /** @see SPEC_REGISTRY ARB-005 */
    #[Group('spec-ARB-005')]
    public function test_spec_arb_005_low_confidence_escalation(): void
    {
        $svc = new ArbiterService(
            [new StubArbiterProvider],
            'stub',
            0.35,
            0.55,
        );
        // Long message but no evidence hashes → confidence 0.45: above no-AI, below manual threshold after LLM
        $out = $svc->buildArbiterMessage([
            'lot_id' => 1,
            'requester_side' => 'buyer',
            'message' => str_repeat('x', 50),
            'has_replay' => false,
        ]);
        $this->assertSame('manual_review', $out['arbiter_status']);
        $this->assertSame('stub', $out['provider_used']);
        $this->assertSame('low_confidence', $out['reason_code']);
        $this->assertStringContainsString('Escalated to manual review', (string) $out['message']);
    }
}

<?php

namespace Tests\Feature;

use App\Mail\ArbiterOperatorEscalationMail;
use App\Services\Arbiter\ArbiterService;
use App\Services\Arbiter\Contracts\ArbiterProvider;
use Illuminate\Support\Facades\Mail;
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
        Mail::fake();
        config(['arbiter_operator_mail.escalation_email' => '']);

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
        $this->assertStringContainsString('could not be reached', strtolower((string) $out['message']));
        $this->assertArrayHasKey('operator_escalation_email_sent', $out);
        $this->assertFalse($out['operator_escalation_email_sent']);
    }

    #[Group('spec-ARB-006')]
    public function test_spec_arb_006_all_providers_fail_operator_mail_when_inbox_configured(): void
    {
        Mail::fake();
        config(['arbiter_operator_mail.escalation_email' => 'operator-escalation@example.test']);

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
            0.35,
            0.55,
        );

        $out = $svc->buildArbiterMessage([
            'lot_id' => 42,
            'requester_side' => 'buyer',
            'message' => str_repeat('a', 50),
            'pdf_sha256' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'has_replay' => false,
        ]);

        $this->assertSame('provider_unavailable', $out['reason_code']);
        $this->assertTrue($out['operator_escalation_email_sent']);
        $this->assertStringContainsString('notified by email', strtolower((string) $out['message']));
        Mail::assertSent(ArbiterOperatorEscalationMail::class, function (ArbiterOperatorEscalationMail $mail): bool {
            return $mail->policyReasonCode === 'provider_unavailable'
                && $mail->lotId === '42'
                && $mail->operatorEmail === 'operator-escalation@example.test';
        });
    }
}

<?php

namespace Tests\Unit;

use App\Support\ArbiterOperatorEscalationDigest;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
class ArbiterOperatorEscalationDigestTest extends TestCase
{
    public function test_email_subject_includes_transaction_id_when_present(): void
    {
        $subject = ArbiterOperatorEscalationDigest::emailSubject(
            '12',
            'policy_manual',
            'participant_requested_review',
            'buyer',
            '456',
        );

        $this->assertSame(
            '[XBoard] Dispute lot 12 tx 456 — Buyer requested operator review',
            $subject,
        );
    }

    public function test_email_subject_omits_transaction_segment_when_unknown(): void
    {
        $subject = ArbiterOperatorEscalationDigest::emailSubject(
            '12',
            'policy_manual',
            'insufficient_context',
            null,
            null,
        );

        $this->assertSame(
            '[XBoard] Dispute on lot 12 — not enough information for automatic review',
            $subject,
        );
    }
}

<?php

namespace Tests\Unit;

use App\Support\ArbiterDealContextPlainText;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-ARB-012')]
class ArbiterDealContextPlainTextTest extends TestCase
{
    public function test_build_includes_lot_and_state(): void
    {
        $s = ArbiterDealContextPlainText::build([
            'lot_id' => 99,
            'requester_side' => 'seller',
            'message' => str_repeat('m', 40),
            'pdf_sha256' => str_repeat('c', 64),
            'exchange_rules_digest' => 'digest',
            'deal_machine_state' => 'completed',
            'recent_chat_digest' => '(none)',
            'receipt_pdf_llm' => 'test',
            'receipt_pdf_text_excerpt' => '',
        ], ['confidence' => 0.7, 'has_replay' => false]);

        $this->assertStringContainsString('lot_id: 99', $s);
        $this->assertStringContainsString('completed', $s);
        $this->assertStringContainsString('digest', $s);
    }
}

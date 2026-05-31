<?php

namespace Tests\Unit;

use App\Support\DealBuyerEscrowTokenHash;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression: buyer deal_completed_buyer must not hit start_deal_required when buyer_lot_email
 * was cleared but buyer_escrow_token_hash still matches the request token.
 *
 * @see docs/SPEC_REGISTRY.md LOT-009
 */
#[Group('spec')]
#[Group('spec-LOT-009')]
class RequestBuyerTokenHashFromRequestTest extends TestCase
{
    public function test_spec_lot_009_reads_token_hash_from_escrow_token_header(): void
    {
        $request = Request::create('/api/v1/exchange-lots/1/send-message', 'POST', [], [], [], [
            'HTTP_X-Escrow-Token' => json_encode(['token_hash' => 'AbCdEf12', 'currency' => 'xmr']),
        ]);

        $this->assertSame('abcdef12', DealBuyerEscrowTokenHash::fromRequest($request));
    }

    public function test_spec_lot_009_reads_token_hash_from_escrow_token_data_body(): void
    {
        $request = Request::create('/api/v1/exchange-lots/1/send-message', 'POST', [
            'escrow_token_data' => ['token_hash' => 'HASH99'],
        ]);

        $this->assertSame('hash99', DealBuyerEscrowTokenHash::fromRequest($request));
    }
}

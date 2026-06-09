<?php

namespace Tests\Unit;

use App\Support\DealArbitrationMilestonesDigest;
use App\Support\DealChatTagSequenceValidator;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
class DealArbitrationMilestonesDigestTest extends TestCase
{
    public function test_wait_seller_transfer_state_explains_seller_confirmed_fiat(): void
    {
        $meaning = DealArbitrationMilestonesDigest::stateMeaning(
            DealChatTagSequenceValidator::STATE_WAIT_SELLER_TRANSFER
        );
        $this->assertStringContainsString('seller_received_funds', strtolower($meaning));
        $this->assertStringContainsString('crypto payout', strtolower($meaning));
    }
}

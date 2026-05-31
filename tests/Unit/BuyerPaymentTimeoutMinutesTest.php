<?php

namespace Tests\Unit;

use App\Support\BuyerPaymentTimeoutMinutes;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
class BuyerPaymentTimeoutMinutesTest extends TestCase
{
    public function test_allowed_options_are_seven_ten_fifteen(): void
    {
        $this->assertSame([7, 10, 15], BuyerPaymentTimeoutMinutes::ALLOWED);
        $this->assertSame(15, BuyerPaymentTimeoutMinutes::DEFAULT);
    }

    public function test_normalize_rejects_legacy_values(): void
    {
        $this->assertSame(15, BuyerPaymentTimeoutMinutes::normalize(null));
        $this->assertSame(7, BuyerPaymentTimeoutMinutes::normalize(7));
        $this->assertSame(15, BuyerPaymentTimeoutMinutes::normalize(30));
    }

    public function test_effective_for_lot_keeps_legacy_stored_minutes(): void
    {
        $this->assertSame(30, BuyerPaymentTimeoutMinutes::effectiveForLot(30));
        $this->assertSame(20, BuyerPaymentTimeoutMinutes::effectiveForLot(20));
        $this->assertSame(15, BuyerPaymentTimeoutMinutes::effectiveForLot(null));
    }
}

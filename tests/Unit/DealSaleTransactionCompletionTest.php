<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\DealSaleTransactionCompletion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * @see docs/SPEC_REGISTRY.md LOT-002
 */
#[Group('spec')]
#[Group('spec-LOT-002')]
class DealSaleTransactionCompletionTest extends TestCase
{
    #[DataProvider('buyerCompletedTagProvider')]
    public function test_spec_lot_002_deal_tag_buyer_completed_for_lot(
        ?array $tag,
        int $lotId,
        bool $expected,
    ): void {
        $lot = new ExchangeLot;
        $lot->id = $lotId;
        $this->assertSame($expected, DealSaleTransactionCompletion::dealTagIsBuyerCompletedForLot($tag, $lot));
    }

    /**
     * @return iterable<string, array{0: ?array, 1: int, 2: bool}>
     */
    public static function buyerCompletedTagProvider(): iterable
    {
        yield 'buyer completed matching lot (string lot_id)' => [
            ['t' => 'deal_completed_buyer', 'lot_id' => '42'],
            42,
            true,
        ];
        yield 'buyer completed matching lot (int lot_id)' => [
            ['t' => 'deal_completed_buyer', 'lot_id' => 7],
            7,
            true,
        ];
        yield 'wrong tag type' => [
            ['t' => 'deal_completed_seller', 'lot_id' => '42'],
            42,
            false,
        ];
        yield 'lot_id mismatch' => [
            ['t' => 'deal_completed_buyer', 'lot_id' => '41'],
            42,
            false,
        ];
        yield 'null tag' => [
            null,
            42,
            false,
        ];
        yield 'missing lot_id' => [
            ['t' => 'deal_completed_buyer'],
            42,
            false,
        ];
    }
}

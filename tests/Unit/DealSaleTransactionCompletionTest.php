<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\DealSaleTransactionCompletion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    #[DataProvider('sellerTimeoutCompletionTagProvider')]
    public function test_spec_lot_002_deal_tag_seller_timeout_completion_for_lot(
        ?array $tag,
        int $lotId,
        bool $expected,
    ): void {
        $lot = new ExchangeLot;
        $lot->id = $lotId;
        $this->assertSame($expected, DealSaleTransactionCompletion::dealTagIsSellerTimeoutCompletionForLot($tag, $lot));
    }

    public function test_spec_lot_002_crypto_payout_completed_sale_still_allows_seller_timeout_gate(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('exchange_lots', function (Blueprint $table): void {
            $table->id();
            $table->string('buyer_lot_email')->nullable();
            $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id');
            $table->string('buyer_lot_email')->nullable();
            $table->string('tx_type');
            $table->string('status');
            $table->string('seller_crypto_payout_tx_hash')->nullable();
            $table->timestamps();
        });

        $lotId = DB::table('exchange_lots')->insertGetId([
            'buyer_lot_email' => 'buyer@mail.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transactions')->insert([
            'exchange_lot_id' => $lotId,
            'buyer_lot_email' => 'buyer@mail.test',
            'tx_type' => 'sale',
            'status' => 'completed',
            'seller_crypto_payout_tx_hash' => 'abc123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lot = ExchangeLot::query()->findOrFail($lotId);
        $this->assertTrue(DealSaleTransactionCompletion::lotHasCompletedCryptoPayoutSaleWithBuyerStillBound($lot));
    }

    public function test_spec_lot_002_completed_sale_without_crypto_payout_is_not_open_amicable_close(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('exchange_lots', function (Blueprint $table): void {
            $table->id();
            $table->string('buyer_lot_email')->nullable();
            $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id');
            $table->string('buyer_lot_email')->nullable();
            $table->string('tx_type');
            $table->string('status');
            $table->string('seller_crypto_payout_tx_hash')->nullable();
            $table->timestamps();
        });

        $lotId = DB::table('exchange_lots')->insertGetId([
            'buyer_lot_email' => 'buyer@mail.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transactions')->insert([
            'exchange_lot_id' => $lotId,
            'buyer_lot_email' => 'buyer@mail.test',
            'tx_type' => 'sale',
            'status' => 'completed',
            'seller_crypto_payout_tx_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lot = ExchangeLot::query()->findOrFail($lotId);
        $this->assertFalse(DealSaleTransactionCompletion::lotHasCompletedCryptoPayoutSaleWithBuyerStillBound($lot));
    }

    /**
     * @return iterable<string, array{0: ?array, 1: int, 2: bool}>
     */
    public static function sellerTimeoutCompletionTagProvider(): iterable
    {
        yield 'seller timeout matching lot (string lot_id)' => [
            ['t' => 'deal_completed_seller_timeout', 'lot_id' => '42'],
            42,
            true,
        ];
        yield 'seller timeout matching lot (int lot_id)' => [
            ['t' => 'deal_completed_seller_timeout', 'lot_id' => 7],
            7,
            true,
        ];
        yield 'wrong tag type' => [
            ['t' => 'deal_completed_buyer', 'lot_id' => '42'],
            42,
            false,
        ];
        yield 'lot_id mismatch' => [
            ['t' => 'deal_completed_seller_timeout', 'lot_id' => '41'],
            42,
            false,
        ];
        yield 'null tag' => [
            null,
            42,
            false,
        ];
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

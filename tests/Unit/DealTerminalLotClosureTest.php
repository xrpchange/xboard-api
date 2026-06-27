<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\DealTerminalLotClosure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @group spec */
class DealTerminalLotClosureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('exchange_lots');
        Schema::create('exchange_lots', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_training')->default(false);
            $table->string('buyer_lot_email')->nullable();
            $table->string('buyer_escrow_token_hash')->nullable();
            $table->decimal('buyer_selected_amount', 20, 8)->nullable();
            $table->string('buyer_seen_ip')->nullable();
            $table->boolean('buyer_ip_share_with_seller')->default(false);
            $table->decimal('sold_crypto_amount', 20, 8)->default(0);
            $table->unsignedInteger('completed_deals_count')->default(0);
            $table->timestamps();
        });
    }

    /** @group spec-LOT-021 */
    public function test_training_lot_release_resets_sold_crypto_amount_for_search(): void
    {
        $lot = ExchangeLot::query()->create([
            'is_training' => true,
            'buyer_lot_email' => 'buyer@example.com',
            'buyer_escrow_token_hash' => 'abc123',
            'sold_crypto_amount' => 5,
            'completed_deals_count' => 1,
        ]);

        DealTerminalLotClosure::releaseLotBuyerBinding($lot->fresh());

        $fresh = ExchangeLot::query()->find($lot->id);
        $this->assertNull($fresh->buyer_lot_email);
        $this->assertSame('abc123', $fresh->buyer_escrow_token_hash);
        $this->assertSame('0.00000000', (string) $fresh->sold_crypto_amount);
        $this->assertSame(1, (int) $fresh->completed_deals_count);
    }

    /** @group spec-LOT-002 */
    public function test_regular_lot_release_keeps_sold_crypto_amount(): void
    {
        $lot = ExchangeLot::query()->create([
            'is_training' => false,
            'buyer_lot_email' => 'buyer@example.com',
            'buyer_escrow_token_hash' => 'abc123',
            'sold_crypto_amount' => 5,
        ]);

        DealTerminalLotClosure::releaseLotBuyerBinding($lot->fresh());

        $fresh = ExchangeLot::query()->find($lot->id);
        $this->assertNull($fresh->buyer_lot_email);
        $this->assertSame('abc123', $fresh->buyer_escrow_token_hash);
        $this->assertSame('5.00000000', (string) $fresh->sold_crypto_amount);
    }
}

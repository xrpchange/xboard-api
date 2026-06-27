<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\DealChatMessage;
use App\Models\ExchangeLot;
use App\Services\Deal\TrainingDealAbandonedLotRelease;
use App\Support\ApiDeploymentEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @group spec */
class TrainingDealAbandonedLotReleaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('deal_chat_messages');
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
            $table->text('wallet_address')->nullable();
            $table->timestamps();
        });
        Schema::create('deal_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id');
            $table->text('deal_tag')->nullable();
            $table->timestamps();
        });
    }

    /** @group spec-LOT-022 */
    public function test_skips_release_on_production_host(): void
    {
        Config::set('training_deal.enabled', true);
        Config::set('app.url', 'https://api.xdexboard.com');

        $lot = ExchangeLot::query()->create([
            'is_training' => true,
            'buyer_lot_email' => 'buyer@example.com',
        ]);
        $this->insertStartDealTag($lot->id, now('UTC')->subMinutes(30));

        $released = $this->app->make(TrainingDealAbandonedLotRelease::class)->releaseStaleLockedLots();

        $this->assertSame(0, $released);
        $this->assertSame('buyer@example.com', ExchangeLot::query()->find($lot->id)->buyer_lot_email);
        $this->assertSame(ApiDeploymentEnvironment::PRODUCTION, ApiDeploymentEnvironment::current());
    }

    /** @group spec-LOT-022 */
    public function test_keeps_recent_training_deal_locked(): void
    {
        Config::set('training_deal.enabled', true);
        Config::set('training_deal.abandoned_deal_minutes', 15);
        Config::set('app.url', 'https://sandbox.api.xdexboard.com');

        $lot = ExchangeLot::query()->create([
            'is_training' => true,
            'buyer_lot_email' => 'buyer@example.com',
        ]);
        $this->insertStartDealTag($lot->id, now('UTC')->subMinutes(10));

        $released = $this->app->make(TrainingDealAbandonedLotRelease::class)->releaseStaleLockedLots();

        $this->assertSame(0, $released);
        $this->assertSame('buyer@example.com', ExchangeLot::query()->find($lot->id)->buyer_lot_email);
    }

    /** @group spec-LOT-022 */
    public function test_releases_abandoned_training_deal_for_search(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true], 200),
        ]);

        Config::set('training_deal.enabled', true);
        Config::set('training_deal.abandoned_deal_minutes', 15);
        Config::set('app.url', 'https://sandbox.api.xdexboard.com');

        $lot = ExchangeLot::query()->create([
            'is_training' => true,
            'buyer_lot_email' => 'buyer@example.com',
            'buyer_escrow_token_hash' => 'abc123',
            'sold_crypto_amount' => 5,
            'wallet_address' => '{"token_hash":"t1","currency":"USDT","wallet_address":"GTEST"}',
        ]);
        $this->insertStartDealTag($lot->id, now('UTC')->subMinutes(20));

        $released = $this->app->make(TrainingDealAbandonedLotRelease::class)->releaseStaleLockedLots();

        $this->assertSame(1, $released);
        $fresh = ExchangeLot::query()->find($lot->id);
        $this->assertNull($fresh->buyer_lot_email);
        $this->assertNull($fresh->buyer_escrow_token_hash);
        $this->assertSame('0.00000000', (string) $fresh->sold_crypto_amount);
    }

    private function insertStartDealTag(int $lotId, CarbonImmutable $at): void
    {
        DealChatMessage::query()->create([
            'exchange_lot_id' => $lotId,
            'deal_tag' => json_encode(['t' => 'start_deal', 'lot_id' => $lotId]),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}

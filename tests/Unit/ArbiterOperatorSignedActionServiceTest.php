<?php

namespace Tests\Unit;

use App\Models\DealChatMessage;
use App\Models\DealDispute;
use App\Models\ExchangeLot;
use App\Services\Arbiter\ArbiterOperatorSignedActionService;
use App\Services\Deal\DealReservationPayoutToBuyerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ArbiterOperatorSignedActionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('exchange_lots', function (Blueprint $table): void {
            $table->id();
            $table->string('buyer_lot_email')->nullable();
            $table->string('seller_lot_email')->nullable();
            $table->text('wallet_address')->nullable();
            $table->timestamps();
        });
        Schema::create('deal_disputes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id');
            $table->string('status', 32);
            $table->string('resolved_by', 32)->nullable();
            $table->text('resolution')->nullable();
            $table->string('arbiter_verdict', 32)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });
        Schema::create('deal_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id');
            $table->string('sender_side', 32);
            $table->string('from_email')->nullable();
            $table->string('to_email')->nullable();
            $table->text('body')->nullable();
            $table->text('deal_tag')->nullable();
            $table->timestamps();
        });
        Schema::create('lot_mailboxes', function (Blueprint $table): void {
            $table->id();
            $table->string('local_part');
            $table->string('domain');
            $table->timestamps();
        });
    }

    public function test_compensate_buyer_closes_dispute_after_successful_payout(): void
    {
        Mail::fake();

        $payout = $this->createMock(DealReservationPayoutToBuyerService::class);
        $payout->method('execute')->willReturn([
            'success' => true,
            'error' => null,
            'crypto_tx_id' => 'tx_1',
            'transaction_id' => 42,
        ]);
        $this->app->instance(DealReservationPayoutToBuyerService::class, $payout);

        $lot = ExchangeLot::query()->create([]);
        DealDispute::query()->create([
            'exchange_lot_id' => $lot->id,
            'status' => 'in_review',
        ]);

        $service = app(ArbiterOperatorSignedActionService::class);
        $result = $service->compensateBuyer($lot);

        $this->assertTrue($result['ok']);
        $this->assertSame('tx_1', $result['payout']['crypto_tx_id']);
        $this->assertSame('closed', DealDispute::query()->first()->status);
        $this->assertStringContainsString('tx_1', DealChatMessage::query()->first()->body ?? '');
    }

    public function test_compensate_buyer_does_not_close_dispute_when_payout_fails(): void
    {
        Mail::fake();

        $payout = $this->createMock(DealReservationPayoutToBuyerService::class);
        $payout->method('execute')->willReturn([
            'success' => false,
            'error' => 'core_http_400:insufficient',
            'crypto_tx_id' => null,
            'transaction_id' => 42,
        ]);
        $this->app->instance(DealReservationPayoutToBuyerService::class, $payout);

        $lot = ExchangeLot::query()->create([]);
        DealDispute::query()->create([
            'exchange_lot_id' => $lot->id,
            'status' => 'in_review',
        ]);

        $service = app(ArbiterOperatorSignedActionService::class);
        $result = $service->compensateBuyer($lot);

        $this->assertFalse($result['ok']);
        $this->assertSame('in_review', DealDispute::query()->first()->status);
        $this->assertSame(0, DealChatMessage::query()->count());
    }

    public function test_deny_buyer_closes_dispute_without_payout(): void
    {
        Mail::fake();

        $lot = ExchangeLot::query()->create([]);
        DealDispute::query()->create([
            'exchange_lot_id' => $lot->id,
            'status' => 'opened',
        ]);

        $service = app(ArbiterOperatorSignedActionService::class);
        $result = $service->applyDisputeVerdict($lot, 'deny_buyer', 'favor_seller');

        $this->assertSame('favor_seller', $result['verdict']);
        $this->assertSame('closed', DealDispute::query()->first()->status);
        $this->assertSame(1, DealChatMessage::query()->count());
    }
}

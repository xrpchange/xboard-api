<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Models\Transaction;
use App\Services\Deal\DealReservationPayoutToBuyerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DealReservationPayoutToBuyerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('services.core.url', 'https://core.test');
        Config::set('services.core.key', 'test-key');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('crypto_currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('network')->nullable();
            $table->boolean('is_native')->default(true);
            $table->timestamps();
        });
        Schema::create('exchange_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('from_crypto_currency_id')->nullable();
            $table->string('from_currency')->nullable();
            $table->string('to_currency')->nullable();
            $table->decimal('rate', 20, 8)->default(1);
            $table->decimal('buyer_selected_amount', 20, 8)->nullable();
            $table->decimal('sell_amount', 20, 8)->nullable();
            $table->decimal('sold_crypto_amount', 20, 8)->default(0);
            $table->string('buyer_lot_email')->nullable();
            $table->string('seller_lot_email')->nullable();
            $table->text('wallet_address')->nullable();
            $table->boolean('aml')->default(false);
            $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id');
            $table->string('buyer_lot_email')->nullable();
            $table->string('tx_type', 32);
            $table->string('status', 32);
            $table->string('crypto_address')->nullable();
            $table->string('seller_crypto_payout_tx_hash')->nullable();
            $table->text('aml_check_json')->nullable();
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

    public function test_execute_calls_core_and_completes_pending_sale(): void
    {
        Mail::fake();
        Http::fake([
            'https://core.test/api/deal-payout-to-buyer-from-reservation' => Http::response([
                'data' => ['transaction_id' => 'onchain_tx_99'],
            ], 200),
            'https://core.test/api/complete-reservation' => Http::response([
                'data' => ['service_fee_collected' => true],
            ], 200),
        ]);

        DB::table('crypto_currencies')->insert([
            'id' => 1,
            'code' => 'xmr',
            'network' => 'native',
            'is_native' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lot = ExchangeLot::query()->create([
            'from_crypto_currency_id' => 1,
            'from_currency' => 'xmr',
            'to_currency' => 'usd',
            'rate' => 100,
            'buyer_selected_amount' => 1.5,
            'buyer_lot_email' => 'buyer-lot@test.local',
            'wallet_address' => json_encode([
                'wallet_address' => '4SellerEscrowTokenAddress',
                'token_hash' => str_repeat('a', 64),
                'currency' => 'XMR',
            ], JSON_UNESCAPED_SLASHES),
            'aml' => false,
        ]);

        Transaction::query()->create([
            'exchange_lot_id' => $lot->id,
            'buyer_lot_email' => 'buyer-lot@test.local',
            'tx_type' => 'sale',
            'status' => 'pending',
            'crypto_address' => '4BuyerXmrAddressExample',
        ]);

        $service = app(DealReservationPayoutToBuyerService::class);
        $result = $service->execute($lot, 'operator_signed_compensate_buyer');

        $this->assertTrue($result['success'], (string) ($result['error'] ?? 'unknown'));
        $this->assertSame('onchain_tx_99', $result['crypto_tx_id']);

        $tx = Transaction::query()->where('exchange_lot_id', $lot->id)->first();
        $this->assertSame('completed', $tx->status);
        $this->assertSame('onchain_tx_99', $tx->seller_crypto_payout_tx_hash);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://core.test/api/deal-payout-to-buyer-from-reservation'
                && $request['destination_address'] === '4BuyerXmrAddressExample';
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://core.test/api/complete-reservation'
                && $request['exchange_lot_id'] === '1'
                && $request['crypto_type'] === 'xmr'
                && str_contains((string) $request['escrow_token'], '4SellerEscrowTokenAddress');
        });
    }
}

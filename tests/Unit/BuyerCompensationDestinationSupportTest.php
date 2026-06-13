<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\BuyerCompensationDestinationSupport;
use App\Support\DealPayoutDestinationAddressValidation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BuyerCompensationDestinationSupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
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
            $table->string('buyer_lot_email')->nullable();
            $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id');
            $table->string('buyer_lot_email')->nullable();
            $table->string('tx_type', 32);
            $table->string('status', 32);
            $table->timestamps();
        });
        Schema::create('deal_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id');
            $table->string('sender_side', 32);
            $table->text('deal_tag')->nullable();
            $table->timestamps();
        });
    }

    public function test_validate_xmr_address_rejects_short_value(): void
    {
        $error = DealPayoutDestinationAddressValidation::validate('4short', 'xmr');
        $this->assertNotNull($error);
        $this->assertSame('crypto_address_invalid', $error['error_code']);
    }

    public function test_validate_tron_usdt_requires_t_address(): void
    {
        $error = DealPayoutDestinationAddressValidation::validate('0x'.str_repeat('a', 40), 'usdt_tron');
        $this->assertNotNull($error);
        $this->assertContains($error['error_code'], ['crypto_address_invalid', 'crypto_address_network_mismatch']);
    }

    public function test_resolve_latest_from_chat_returns_newest_buyer_tag(): void
    {
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
            'buyer_lot_email' => 'buyer@test.local',
        ]);
        DB::table('transactions')->insert([
            'id' => 10,
            'exchange_lot_id' => $lot->id,
            'buyer_lot_email' => 'buyer@test.local',
            'tx_type' => 'sale',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $addr = '4'.str_repeat('B', 94);
        DB::table('deal_chat_messages')->insert([
            'exchange_lot_id' => $lot->id,
            'sender_side' => 'buyer',
            'deal_tag' => json_encode([
                't' => BuyerCompensationDestinationSupport::TAG,
                'lot_id' => $lot->id,
                'tx_id' => 10,
                'crypto' => 'xmr',
                'address' => $addr,
                'set_by' => 'buyer',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = BuyerCompensationDestinationSupport::resolveLatestFromChat($lot, 10);
        $this->assertNotNull($resolved);
        $this->assertSame($addr, $resolved['address']);
        $this->assertSame('xmr', $resolved['crypto']);
    }

    public function test_validate_and_enrich_incoming_tag_rejects_crypto_mismatch(): void
    {
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
            'buyer_lot_email' => 'buyer@test.local',
        ]);
        $tag = [
            't' => BuyerCompensationDestinationSupport::TAG,
            'lot_id' => $lot->id,
            'crypto' => 'xrp',
            'address' => '4'.str_repeat('C', 94),
        ];
        $error = BuyerCompensationDestinationSupport::validateAndEnrichIncomingTag($lot, $tag);
        $this->assertNotNull($error);
        $this->assertSame('buyer_compensation_destination_crypto_mismatch', $error['error_code']);
    }
}

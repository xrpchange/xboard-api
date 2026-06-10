<?php

namespace Tests\Unit;

use App\Support\EscrowTokenLotLimit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * @see docs/SPEC_REGISTRY.md LOT-018
 */
#[Group('spec')]
#[Group('spec-LOT-018')]
class EscrowTokenLotLimitTest extends TestCase
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
            $table->unsignedBigInteger('node_id')->nullable();
            $table->string('wallet_hash', 64)->nullable();
            $table->text('wallet_address')->nullable();
            $table->decimal('sell_amount', 20, 8)->default(0);
            $table->timestamps();
        });
    }

    public function test_spec_lot_018_limit_reached_at_default_cap(): void
    {
        config(['lot.max_lots_per_escrow_token' => 2]);
        $hash = 'a'.str_repeat('b', 63);

        DB::table('exchange_lots')->insert([
            ['wallet_hash' => $hash, 'created_at' => now(), 'updated_at' => now()],
            ['wallet_hash' => $hash, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertTrue(EscrowTokenLotLimit::isLimitReached($hash));
        $this->assertSame(2, EscrowTokenLotLimit::countForWalletHash($hash));
    }

    public function test_spec_lot_018_zero_cap_disables_limit(): void
    {
        config(['lot.max_lots_per_escrow_token' => 0]);
        $hash = 'c'.str_repeat('d', 63);

        for ($i = 0; $i < 15; $i++) {
            DB::table('exchange_lots')->insert([
                'wallet_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertFalse(EscrowTokenLotLimit::isLimitReached($hash));
    }
}

<?php

namespace Tests\Unit;

use App\Support\ExchangeLotDealCounters;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * @see docs/SPEC_REGISTRY.md LOT-019
 */
#[Group('spec')]
#[Group('spec-LOT-019')]
class ExchangeLotDealCountersTest extends TestCase
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
            $table->unsignedInteger('completed_deals_count')->default(0);
            $table->unsignedInteger('bad_deals_count')->default(0);
            $table->timestamps();
        });
    }

    public function test_spec_lot_019_increments_completed_and_bad_deal_counters(): void
    {
        $lotId = DB::table('exchange_lots')->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ExchangeLotDealCounters::incrementCompletedDeal($lotId);
        ExchangeLotDealCounters::incrementCompletedDeal($lotId);
        ExchangeLotDealCounters::incrementBadDeal($lotId);

        $row = DB::table('exchange_lots')->where('id', $lotId)->first();
        $this->assertSame(2, (int) $row->completed_deals_count);
        $this->assertSame(1, (int) $row->bad_deals_count);
    }
}

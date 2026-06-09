<?php

namespace Tests\Unit;

use App\Services\Deal\DealInternalMailRateLimitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DealInternalMailRateLimitServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Schema::create('deal_internal_mail_daily_counters', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255);
            $table->date('day');
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['email', 'day']);
        });
    }

    public function test_reserve_send_slot_stops_at_daily_limit(): void
    {
        Config::set('deal.internal_mail_daily_limit', 2);
        $service = new DealInternalMailRateLimitService;

        $this->assertTrue($service->reserveSendSlot('buyer-lot@mail.test'));
        $this->assertTrue($service->reserveSendSlot('buyer-lot@mail.test'));
        $this->assertFalse($service->reserveSendSlot('buyer-lot@mail.test'));
        $this->assertSame(2, $service->sentCountToday('buyer-lot@mail.test'));
    }

    public function test_exhaust_for_utc_day_blocks_without_separate_flag(): void
    {
        Config::set('deal.internal_mail_daily_limit', 300);
        $service = new DealInternalMailRateLimitService;

        $this->assertTrue($service->exhaustForUtcDay('Seller.Lot@mail.test'));
        $this->assertFalse($service->reserveSendSlot('seller.lot@mail.test'));

        $row = DB::table('deal_internal_mail_daily_counters')
            ->where('email', 'seller.lot@mail.test')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(300, (int) $row->sent_count);
    }
}

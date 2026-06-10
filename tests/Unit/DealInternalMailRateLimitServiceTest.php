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

    public function test_record_successful_send_stops_at_daily_limit(): void
    {
        Config::set('deal.internal_mail_daily_limit', 2);
        $service = new DealInternalMailRateLimitService;

        $this->assertFalse($service->isDailyLimitReached('buyer-lot@mail.test'));
        $service->recordSuccessfulSend('buyer-lot@mail.test');
        $this->assertFalse($service->isDailyLimitReached('buyer-lot@mail.test'));
        $service->recordSuccessfulSend('buyer-lot@mail.test');
        $this->assertTrue($service->isDailyLimitReached('buyer-lot@mail.test'));
        $this->assertSame(2, $service->sentCountToday('buyer-lot@mail.test'));
    }

    public function test_exhaust_for_utc_day_blocks_without_separate_flag(): void
    {
        Config::set('deal.internal_mail_daily_limit', 300);
        $service = new DealInternalMailRateLimitService;

        $this->assertTrue($service->exhaustForUtcDay('Seller.Lot@mail.test'));
        $this->assertTrue($service->isDailyLimitReached('seller.lot@mail.test'));

        $row = DB::table('deal_internal_mail_daily_counters')
            ->where('email', 'seller.lot@mail.test')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(300, (int) $row->sent_count);
    }

    public function test_long_lot_mailbox_local_part_is_accepted_for_counter(): void
    {
        Config::set('deal.internal_mail_daily_limit', 2);
        $service = new DealInternalMailRateLimitService;

        $longLocal = 'xmr_'.str_repeat('a', 200);
        $email = $longLocal.'@mail.xdexboard.com';

        $this->assertFalse((bool) filter_var($email, FILTER_VALIDATE_EMAIL));
        $this->assertSame($email, $service->normalizeMailboxEmail($email));

        $service->recordSuccessfulSend($email);
        $this->assertSame(1, $service->sentCountToday($email));
    }

    public function test_clear_today_for_mailbox_resets_counter(): void
    {
        Config::set('deal.internal_mail_daily_limit', 300);
        $service = new DealInternalMailRateLimitService;

        $service->exhaustForUtcDay('reset-me@mail.test');
        $this->assertTrue($service->isDailyLimitReached('reset-me@mail.test'));

        $deleted = $service->clearTodayForMailbox('reset-me@mail.test');
        $this->assertSame(1, $deleted);
        $this->assertFalse($service->isDailyLimitReached('reset-me@mail.test'));
    }
}

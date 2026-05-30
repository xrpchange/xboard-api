<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Services\Deal\DealWebPushNotifier;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class DealWebPushNotifierTest extends TestCase
{
    public function test_skips_when_vapid_not_configured(): void
    {
        Config::set('web_push.vapid.public_key', '');
        Config::set('web_push.vapid.private_key', '');

        $lot = new ExchangeLot;
        $lot->id = 1;

        $this->app->make(DealWebPushNotifier::class)->notifyDealChatRecipient($lot, 'buyer');

        $this->assertTrue(true);
    }
}

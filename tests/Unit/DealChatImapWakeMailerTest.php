<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Services\Deal\DealChatImapWakeMailer;
use App\Services\LotMailboxService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class DealChatImapWakeMailerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LotMailboxService::class, function ($mock): void {
            $mock->shouldReceive('getMailboxEmailForAddress')->andReturn('');
        });
    }

    public function test_sends_wake_mail_to_seller_and_buyer_lot_mailboxes(): void
    {
        Mail::fake();

        $lot = new ExchangeLot([
            'id' => 9001,
            'wallet_address' => '{"token_hash":"ab","currency":"XMR"}',
            'seller_lot_email' => 'seller_lot_mailbox@example.test',
            'buyer_lot_email' => 'buyer_lot_mailbox@example.test',
        ]);

        $this->app->make(DealChatImapWakeMailer::class)->pokeMailboxesAfterServerChatWrite($lot);

        Mail::assertOutgoingCount(2);
    }

    public function test_dedupes_when_buyer_and_seller_mailbox_are_same(): void
    {
        Mail::fake();

        $same = 'same_mailbox@example.test';
        $lot = new ExchangeLot([
            'id' => 9002,
            'wallet_address' => '{"token_hash":"cd","currency":"XMR"}',
            'seller_lot_email' => $same,
            'buyer_lot_email' => $same,
        ]);

        $this->app->make(DealChatImapWakeMailer::class)->pokeMailboxesAfterServerChatWrite($lot);

        Mail::assertOutgoingCount(1);
    }
}

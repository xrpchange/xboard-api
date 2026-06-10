<?php

namespace Tests\Feature;

use App\Models\DealDispute;
use App\Models\ExchangeLot;
use App\Services\Deal\DealReservationPayoutToBuyerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-ARB-012')]
class ArbiterOperatorSignedActionTest extends TestCase
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
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id');
            $table->string('buyer_lot_email')->nullable();
            $table->timestamps();
        });
        Schema::create('lot_mailboxes', function (Blueprint $table): void {
            $table->id();
            $table->string('local_part');
            $table->string('domain');
            $table->timestamps();
        });
    }

    public function test_compensate_buyer_signed_get_shows_confirm_form(): void
    {
        $lot = ExchangeLot::query()->create([]);
        $url = URL::temporarySignedRoute('arbiter.operator.action', now()->addHour(), [
            'lot' => $lot->id,
            'action' => 'compensate_buyer',
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Compensate buyer', false)
            ->assertSee('Confirm: Pay buyer from reservation and close dispute', false);
    }

    public function test_compensate_buyer_signed_post_closes_dispute_after_payout(): void
    {
        Mail::fake();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $payout = $this->createMock(DealReservationPayoutToBuyerService::class);
        $payout->method('execute')->willReturn([
            'success' => true,
            'error' => null,
            'crypto_tx_id' => 'chain_tx_77',
            'transaction_id' => 5,
        ]);
        $this->app->instance(DealReservationPayoutToBuyerService::class, $payout);

        $lot = ExchangeLot::query()->create([]);
        DealDispute::query()->create([
            'exchange_lot_id' => $lot->id,
            'status' => 'in_review',
        ]);

        $url = URL::temporarySignedRoute('arbiter.operator.action', now()->addHour(), [
            'lot' => $lot->id,
            'action' => 'compensate_buyer',
        ]);

        $this->post($url)
            ->assertOk()
            ->assertSee('Decision recorded', false)
            ->assertSee('chain_tx_77', false);

        $dispute = DealDispute::query()->where('exchange_lot_id', $lot->id)->first();
        $this->assertSame('closed', $dispute->status);
        $this->assertSame('favor_buyer', $dispute->arbiter_verdict);
    }

    public function test_block_buyer_internal_mail_signed_route_is_registered(): void
    {
        $lot = ExchangeLot::query()->create([]);
        $url = URL::temporarySignedRoute('arbiter.operator.action', now()->addHour(), [
            'lot' => $lot->id,
            'action' => 'block_buyer_internal_mail',
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Block buyer internal mail', false);
    }

    public function test_compensate_buyer_unsigned_post_is_forbidden(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->post('/arbiter/operator/6/compensate_buyer')->assertForbidden();
    }
}

<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Models\Transaction;
use App\Support\BuyerExternalEmailReputation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * @see docs/SPEC_REGISTRY.md BUY-001
 */
#[Group('spec')]
#[Group('spec-BUY-001')]
class BuyerExternalEmailReputationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('buyer_external_email_reputations', function (Blueprint $table): void {
            $table->string('external_email', 255)->primary();
            $table->unsignedInteger('completed_deals_count')->default(0);
            $table->unsignedInteger('bad_deals_count')->default(0);
            $table->timestamps();
        });

        Schema::create('exchange_lots', function (Blueprint $table): void {
            $table->id();
            $table->string('buyer_notification_email', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id')->nullable();
            $table->string('buyer_notification_email', 255)->nullable();
            $table->timestamps();
        });
    }

    public function test_spec_buy_001_normalizes_email_and_increments_counters(): void
    {
        $email = 'Buyer@Example.COM';

        BuyerExternalEmailReputation::incrementCompletedDeal($email);
        BuyerExternalEmailReputation::incrementCompletedDeal($email);
        BuyerExternalEmailReputation::incrementBadDeal($email);

        $counts = BuyerExternalEmailReputation::countsForEmail($email);
        $this->assertSame(2, $counts['completed_deals_count']);
        $this->assertSame(1, $counts['bad_deals_count']);

        $row = DB::table('buyer_external_email_reputations')
            ->where('external_email', 'buyer@example.com')
            ->first();
        $this->assertNotNull($row);
    }

    public function test_spec_buy_001_increment_completed_for_transaction_uses_snapshot_then_lot(): void
    {
        $lotId = DB::table('exchange_lots')->insertGetId([
            'buyer_notification_email' => 'lot@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tx = new Transaction([
            'exchange_lot_id' => $lotId,
            'buyer_notification_email' => 'tx@example.com',
        ]);
        BuyerExternalEmailReputation::incrementCompletedDealForTransaction($tx);

        $txCounts = BuyerExternalEmailReputation::countsForEmail('tx@example.com');
        $this->assertSame(1, $txCounts['completed_deals_count']);

        $txNoSnapshot = new Transaction([
            'exchange_lot_id' => $lotId,
            'buyer_notification_email' => null,
        ]);
        BuyerExternalEmailReputation::incrementCompletedDealForTransaction($txNoSnapshot);

        $lotCounts = BuyerExternalEmailReputation::countsForEmail('lot@example.com');
        $this->assertSame(1, $lotCounts['completed_deals_count']);
    }

    public function test_spec_buy_001_increment_bad_deal_when_buyer_claim_rejected(): void
    {
        $lot = new ExchangeLot;
        $lot->forceFill(['buyer_notification_email' => 'bad@example.com']);

        BuyerExternalEmailReputation::incrementBadDealForLot($lot);

        $counts = BuyerExternalEmailReputation::countsForEmail('bad@example.com');
        $this->assertSame(1, $counts['bad_deals_count']);
    }
}

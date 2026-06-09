<?php

namespace Tests\Unit;

use App\Models\DealChatMessage;
use App\Models\DealDispute;
use App\Models\ExchangeLot;
use App\Support\DisputeArbiterParticipantPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DisputeArbiterParticipantPolicyTest extends TestCase
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
            $table->timestamps();
        });
        Schema::create('deal_disputes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id');
            $table->string('status', 32)->default('opened');
            $table->timestamp('opened_at')->nullable();
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

    public function test_requires_participant_comment_before_ai(): void
    {
        $lot = ExchangeLot::query()->create([]);
        DealDispute::query()->create([
            'exchange_lot_id' => $lot->id,
            'status' => 'opened',
            'opened_at' => now()->subHour(),
        ]);

        $policy = DisputeArbiterParticipantPolicy::resolveForSide($lot, 'buyer');

        $this->assertSame(DisputeArbiterParticipantPolicy::ACTION_COMMENT_REQUIRED, $policy['action']);
    }

    private function insertChatMessage(int $lotId, string $side, ?string $dealTag, \DateTimeInterface $at): void
    {
        DealChatMessage::unguarded(function () use ($lotId, $side, $dealTag, $at): void {
            DealChatMessage::query()->create([
                'exchange_lot_id' => $lotId,
                'sender_side' => $side,
                'deal_tag' => $dealTag,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        });
    }

    public function test_first_press_after_comment_is_ai(): void
    {
        $lot = ExchangeLot::query()->create([]);
        DealDispute::query()->create([
            'exchange_lot_id' => $lot->id,
            'status' => 'in_review',
            'opened_at' => now()->subHour(),
        ]);
        $this->insertChatMessage($lot->id, 'buyer', null, now()->subMinutes(10));

        $policy = DisputeArbiterParticipantPolicy::resolveForSide($lot, 'buyer');

        $this->assertSame(DisputeArbiterParticipantPolicy::ACTION_AI, $policy['action']);
    }

    public function test_second_press_after_ai_is_operator_escalation(): void
    {
        $lot = ExchangeLot::query()->create([]);
        $openedAt = now()->subHour();
        DealDispute::query()->create([
            'exchange_lot_id' => $lot->id,
            'status' => 'in_review',
            'opened_at' => $openedAt,
        ]);
        $this->insertChatMessage($lot->id, 'buyer', null, $openedAt->copy()->addMinute());
        $this->insertChatMessage(
            $lot->id,
            'arbiter_ai',
            json_encode(['t' => 'arbiter_message', 'source_side' => 'buyer']),
            $openedAt->copy()->addMinutes(2),
        );

        $policy = DisputeArbiterParticipantPolicy::resolveForSide($lot, 'buyer');

        $this->assertSame(DisputeArbiterParticipantPolicy::ACTION_OPERATOR_ESCALATION, $policy['action']);
    }

    public function test_after_escalation_must_wait_for_operator_reply(): void
    {
        $lot = ExchangeLot::query()->create([]);
        $openedAt = now()->subHour();
        DealDispute::query()->create([
            'exchange_lot_id' => $lot->id,
            'status' => 'in_review',
            'opened_at' => $openedAt,
        ]);
        $this->insertChatMessage($lot->id, 'buyer', null, $openedAt->copy()->addMinute());
        $this->insertChatMessage(
            $lot->id,
            'arbiter_ai',
            json_encode(['t' => 'arbiter_message', 'source_side' => 'buyer']),
            $openedAt->copy()->addMinutes(2),
        );
        $this->insertChatMessage(
            $lot->id,
            'system',
            json_encode(['t' => 'operator_escalation_requested', 'requested_by' => 'buyer']),
            $openedAt->copy()->addMinutes(3),
        );

        $policy = DisputeArbiterParticipantPolicy::resolveForSide($lot, 'buyer');

        $this->assertSame(DisputeArbiterParticipantPolicy::ACTION_WAIT_FOR_OPERATOR, $policy['action']);
    }

    public function test_operator_reply_resets_cycle_for_buyer(): void
    {
        $lot = ExchangeLot::query()->create([]);
        $openedAt = now()->subHours(2);
        DealDispute::query()->create([
            'exchange_lot_id' => $lot->id,
            'status' => 'in_review',
            'opened_at' => $openedAt,
        ]);
        $this->insertChatMessage($lot->id, 'buyer', null, $openedAt->copy()->addMinute());
        $this->insertChatMessage(
            $lot->id,
            'arbiter_ai',
            json_encode(['t' => 'arbiter_message', 'source_side' => 'buyer']),
            $openedAt->copy()->addMinutes(2),
        );
        $this->insertChatMessage(
            $lot->id,
            'system',
            json_encode(['t' => 'operator_escalation_requested', 'requested_by' => 'buyer']),
            $openedAt->copy()->addMinutes(3),
        );
        $this->insertChatMessage(
            $lot->id,
            'exchange_operator',
            json_encode(['t' => 'operator_mail_reply']),
            $openedAt->copy()->addMinutes(4),
        );
        $this->insertChatMessage($lot->id, 'buyer', null, $openedAt->copy()->addMinutes(5));

        $policy = DisputeArbiterParticipantPolicy::resolveForSide($lot, 'buyer');

        $this->assertSame(DisputeArbiterParticipantPolicy::ACTION_AI, $policy['action']);
        $this->assertFalse($policy['ai_used_in_cycle']);
        $this->assertFalse($policy['operator_escalation_sent_in_cycle']);
    }
}

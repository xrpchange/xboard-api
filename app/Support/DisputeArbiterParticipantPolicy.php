<?php

namespace App\Support;

use App\Models\DealChatMessage;
use App\Models\DealDispute;
use App\Models\ExchangeLot;
use Carbon\CarbonInterface;

/**
 * Per-side dispute arbiter button policy (one AI call per cycle, then operator mail, then wait for operator reply).
 *
 * Cycle resets after the latest {@see operator_mail_reply} chat row for the lot.
 */
final class DisputeArbiterParticipantPolicy
{
    public const ACTION_AI = 'ai';

    public const ACTION_OPERATOR_ESCALATION = 'operator_escalation';

    public const ACTION_WAIT_FOR_OPERATOR = 'wait_for_operator';

    public const ACTION_COMMENT_REQUIRED = 'comment_required';

    public const ERROR_COMMENT_REQUIRED = 'dispute_participant_comment_required';

    public const ERROR_WAIT_FOR_OPERATOR = 'arbiter_wait_for_operator_reply';

    /**
     * @return array{
     *     action: string,
     *     requester_side: string,
     *     ai_used_in_cycle: bool,
     *     operator_escalation_sent_in_cycle: bool,
     *     operator_replied_in_cycle: bool,
     *     cycle_started_at: string|null,
     *     has_participant_comment: bool
     * }
     */
    public static function resolveForSide(ExchangeLot $lot, string $requesterSide): array
    {
        $side = strtolower(trim($requesterSide));
        if (! in_array($side, ['buyer', 'seller'], true)) {
            $side = 'buyer';
        }

        $cycleStart = self::cycleStartInstant($lot);
        $hasComment = self::hasParticipantCommentSince($lot, $side, $cycleStart);
        $aiUsed = self::countTaggedSince($lot, 'arbiter_message', $side, 'source_side', $cycleStart) > 0;
        $escalationSent = self::countTaggedSince($lot, 'operator_escalation_requested', $side, 'requested_by', $cycleStart) > 0;
        $operatorReplied = self::hasOperatorReplySince($lot, $cycleStart);

        $action = self::ACTION_AI;
        if (! $hasComment) {
            $action = self::ACTION_COMMENT_REQUIRED;
        } elseif (self::isAiEnabled()) {
            if ($aiUsed && $escalationSent) {
                $action = self::ACTION_WAIT_FOR_OPERATOR;
            } elseif ($aiUsed) {
                $action = self::ACTION_OPERATOR_ESCALATION;
            }
        } elseif ($escalationSent) {
            $action = self::ACTION_WAIT_FOR_OPERATOR;
        } else {
            $action = self::ACTION_OPERATOR_ESCALATION;
        }

        return [
            'action' => $action,
            'requester_side' => $side,
            'ai_used_in_cycle' => $aiUsed,
            'operator_escalation_sent_in_cycle' => $escalationSent,
            'operator_replied_in_cycle' => $operatorReplied,
            'cycle_started_at' => $cycleStart?->toIso8601String(),
            'has_participant_comment' => $hasComment,
        ];
    }

    public static function isAiEnabled(): bool
    {
        return (bool) config('services.arbiter.ai_enabled', true);
    }

    public static function cycleStartInstant(ExchangeLot $lot): ?CarbonInterface
    {
        $lastOperatorReply = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('sender_side', 'exchange_operator')
            ->orderByDesc('id')
            ->value('created_at');

        if ($lastOperatorReply !== null) {
            return $lastOperatorReply instanceof CarbonInterface
                ? $lastOperatorReply
                : \Illuminate\Support\Carbon::parse($lastOperatorReply);
        }

        $disputeOpenedAt = DealDispute::query()
            ->where('exchange_lot_id', $lot->id)
            ->orderByDesc('id')
            ->value('opened_at');

        if ($disputeOpenedAt !== null) {
            return $disputeOpenedAt instanceof CarbonInterface
                ? $disputeOpenedAt
                : \Illuminate\Support\Carbon::parse($disputeOpenedAt);
        }

        return null;
    }

    public static function hasParticipantCommentSince(ExchangeLot $lot, string $side, ?CarbonInterface $since): bool
    {
        $query = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('sender_side', $side);

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        return $query->exists();
    }

    public static function hasOperatorReplySince(ExchangeLot $lot, ?CarbonInterface $since): bool
    {
        if ($since === null) {
            return false;
        }

        return DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('sender_side', 'exchange_operator')
            ->where('created_at', '>', $since)
            ->exists();
    }

    private static function countTaggedSince(
        ExchangeLot $lot,
        string $tagType,
        string $side,
        string $sideJsonField,
        ?CarbonInterface $since,
    ): int {
        $query = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereNotNull('deal_tag')
            ->orderBy('id');

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        $count = 0;
        foreach ($query->cursor() as $row) {
            $tag = self::decodeDealTag($row->deal_tag);
            if (($tag['t'] ?? '') !== $tagType) {
                continue;
            }
            if (strtolower(trim((string) ($tag[$sideJsonField] ?? ''))) !== $side) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /** @return array<string, mixed> */
    private static function decodeDealTag(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}

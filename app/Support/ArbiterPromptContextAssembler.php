<?php

namespace App\Support;

use App\Models\DealChatMessage;
use App\Models\ExchangeLot;

/**
 * Builds non-authoritative context for {@see \App\Services\Arbiter\ArbiterService}.
 * Server validators and controllers remain the source of truth for enforceable rules.
 */
final class ArbiterPromptContextAssembler
{
    public const MAX_RULES_CHARS = 200000;

    public const MAX_CHAT_LINES = 45;

    public const MAX_BODY_CHARS = 480;

    public const MAX_TAG_CHARS = 320;

    /**
     * @return array{
     *   exchange_rules_digest: string,
     *   deal_machine_state: string,
     *   deal_machine_state_meaning: string,
     *   deal_milestones_digest: string,
     *   recent_chat_digest: string,
     *   declared_transactions_digest: string,
     * }
     */
    public static function forLot(ExchangeLot $lot, string $requesterSide): array
    {
        $side = strtolower(trim($requesterSide)) === 'seller' ? 'seller' : 'buyer';
        $txProofs = DealArbitrationTxProofCatalog::forLot($lot);
        $dealState = DealChatTagSequenceValidator::computeStateForLot($lot);

        return [
            'exchange_rules_digest' => self::loadRulesDigest(),
            'deal_machine_state' => $dealState,
            'deal_machine_state_meaning' => DealArbitrationMilestonesDigest::stateMeaning($dealState),
            'deal_milestones_digest' => DealArbitrationMilestonesDigest::forLot($lot),
            'recent_chat_digest' => self::buildChatDigest($lot, $side),
            'declared_transactions_digest' => DealArbitrationTxProofCatalog::formatDigestLines($txProofs),
            'deal_transaction_id' => $lot->primaryDealTransactionId(),
        ];
    }

    public static function loadRulesDigest(): string
    {
        $path = resource_path('prompts/arbiter_exchange_rules.md');
        $text = '';
        if (is_readable($path)) {
            $raw = (string) file_get_contents($path);
            $text = trim($raw);
        }
        if ($text === '') {
            $text = 'Rules digest file missing or empty. Be conservative: prefer manual_review; ask for evidence; do not invent payment or address facts.';
        }

        // We intentionally send the full rules file to the arbiter (single source of truth for policy text).
        if (mb_strlen($text) > self::MAX_RULES_CHARS) {
            return mb_substr($text, 0, self::MAX_RULES_CHARS);
        }

        return $text;
    }

    /**
     * Same visibility as {@see \App\Http\Controllers\Api\ExchangeLotController::chatMessages} for the requester.
     */
    private static function buildChatDigest(ExchangeLot $lot, string $requesterSide): string
    {
        $q = DealChatMessage::query()->where('exchange_lot_id', $lot->id);
        if ($requesterSide === 'seller') {
            $q->whereNull('deleted_by_seller_at');
        } else {
            $q->whereNull('deleted_by_buyer_at');
        }
        $rows = $q->orderByDesc('id')->limit(self::MAX_CHAT_LINES)->get()->reverse();
        $lines = [];
        foreach ($rows as $m) {
            if (self::shouldOmitFromLlmChatDigest($m)) {
                continue;
            }
            $body = (string) ($m->body ?? '');
            $body = preg_replace('/\s+/u', ' ', $body) ?? $body;
            $body = mb_substr(trim($body), 0, self::MAX_BODY_CHARS);
            $tag = mb_substr(trim((string) ($m->deal_tag ?? '')), 0, self::MAX_TAG_CHARS);
            $side = (string) ($m->sender_side ?? '?');
            $lines[] = 'id='.$m->id.' side='.$side.' tag='.($tag !== '' ? $tag : '(none)').' body='.($body !== '' ? $body : '(empty)');
        }

        return $lines === [] ? '(no chat messages in requester view)' : implode("\n", $lines);
    }

    private static function shouldOmitFromLlmChatDigest(DealChatMessage $message): bool
    {
        $side = strtolower(trim((string) ($message->sender_side ?? '')));
        if (in_array($side, ['arbiter_ai', 'exchange_operator'], true)) {
            return true;
        }
        $raw = trim((string) ($message->deal_tag ?? ''));
        if ($raw === '' || ! str_starts_with($raw, '{')) {
            return false;
        }
        $tag = json_decode($raw, true);
        if (! is_array($tag)) {
            return false;
        }
        $tagType = trim((string) ($tag['t'] ?? ''));

        return in_array($tagType, ['arbiter_message', 'operator_mail_reply'], true);
    }
}

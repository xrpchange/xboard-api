<?php

namespace App\Services\Arbiter;

use App\Models\DealChatMessage;
use App\Models\DealDispute;
use App\Models\ExchangeLot;
use App\Services\Deal\DealChatImapWakeMailer;
use App\Services\Deal\DealReservationPayoutToBuyerService;
use App\Support\ApiDeploymentEnvironment;
use App\Support\CryptoTxExplorerUrl;
use App\Support\DealEscrowCryptoType;
use App\Support\BuyerExternalEmailReputation;
use App\Support\ExchangeLotDealCounters;
use Illuminate\Support\Facades\Log;

/**
 * Operator actions from signed escalation-mail links (signed URL required at route).
 */
final class ArbiterOperatorSignedActionService
{
    public function __construct(
        private readonly DealChatImapWakeMailer $dealChatImapWakeMailer,
        private readonly DealReservationPayoutToBuyerService $reservationPayoutToBuyer,
    ) {}

    /**
     * Compensate buyer: Core payout from seller reservation, then close dispute + operator chat row.
     *
     * @return array{
     *     ok: bool,
     *     payout: array{success: bool, error: ?string, crypto_tx_id: ?string, transaction_id: ?int},
     *     verdict: string,
     *     disputes_closed: int,
     *     already_closed: bool,
     *     body: string
     * }
     */
    public function compensateBuyer(ExchangeLot $lot): array
    {
        $existing = $this->reservationPayoutToBuyer->findExistingForcedReservationPayout($lot);
        if ($existing !== null) {
            $verdictResult = $this->applyDisputeVerdict($lot, 'compensate_buyer', 'favor_buyer', $existing, skipChat: true);

            return [
                'ok' => true,
                'payout' => $existing,
                'already_compensated' => true,
                ...$verdictResult,
            ];
        }

        $payout = $this->reservationPayoutToBuyer->execute($lot, 'operator_signed_compensate_buyer');
        if (! $payout['success']) {
            Log::warning('[arbiter] operator_compensate_buyer_payout_failed', [
                'lot_id' => $lot->id,
                'error' => $payout['error'],
                'transaction_id' => $payout['transaction_id'],
            ]);

            return [
                'ok' => false,
                'payout' => $payout,
                'verdict' => 'favor_buyer',
                'disputes_closed' => 0,
                'already_closed' => false,
                'body' => '',
            ];
        }

        $verdictResult = $this->applyDisputeVerdict($lot, 'compensate_buyer', 'favor_buyer', $payout, skipChat: false);
        ExchangeLotDealCounters::incrementBadDeal((int) $lot->id);

        return [
            'ok' => true,
            'payout' => $payout,
            'already_compensated' => false,
            ...$verdictResult,
        ];
    }

    /**
     * @return array{verdict: string, disputes_closed: int, already_closed: bool, body: string}
     */
    public function applyDisputeVerdict(
        ExchangeLot $lot,
        string $action,
        string $verdict,
        ?array $payout = null,
        bool $skipChat = false,
    ): array {
        $verdict = strtolower(trim($verdict));
        if (! in_array($verdict, ['favor_buyer', 'favor_seller'], true)) {
            throw new \InvalidArgumentException('Unsupported operator verdict: '.$verdict);
        }

        $active = DealDispute::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereIn('status', ['opened', 'in_review'])
            ->orderByDesc('id')
            ->get();

        $alreadyClosed = $active->isEmpty();
        $closedCount = 0;

        if (! $alreadyClosed) {
            $resolution = $verdict === 'favor_buyer'
                ? $this->resolutionTextForFavorBuyer($payout)
                : 'Exchange operator (signed mail link): favor seller. Dispute closed.';

            foreach ($active as $dispute) {
                $dispute->status = 'closed';
                $dispute->resolved_by = 'human_admin';
                $dispute->resolution = $resolution;
                $dispute->arbiter_verdict = $verdict;
                $dispute->resolved_at = now();
                $dispute->closed_at = now();
                $dispute->last_activity_at = now();
                $dispute->save();
                $closedCount++;
            }

            if ($verdict === 'favor_seller' && $closedCount > 0) {
                BuyerExternalEmailReputation::incrementBadDealForLot($lot);
            }
        }

        $body = $this->operatorChatBody($lot, $verdict, $payout, $alreadyClosed);

        if (! $skipChat) {
            $explorerUrl = $this->explorerUrlForOperatorPayout($lot, $payout);
            $tagPayload = [
                't' => 'operator_mail_reply',
                'lot_id' => (string) $lot->id,
                'operator_action' => $action,
                'operator_verdict' => $verdict,
                'dispute_closed' => ! $alreadyClosed,
                'crypto_payout_tx' => is_array($payout) ? ($payout['crypto_tx_id'] ?? null) : null,
                'laravel_transaction_id' => is_array($payout) ? ($payout['transaction_id'] ?? null) : null,
            ];
            if ($explorerUrl !== null) {
                $tagPayload['explorer_url'] = $explorerUrl;
            }
            $tag = json_encode($tagPayload, JSON_UNESCAPED_UNICODE);

            DealChatMessage::create([
                'exchange_lot_id' => $lot->id,
                'sender_side' => 'exchange_operator',
                'from_email' => null,
                'to_email' => null,
                'body' => $body,
                'deal_tag' => $tag !== false ? $tag : null,
            ]);

            $this->enforceMaxMessagesPerDeal($lot->id);
            $lot->refresh();
            $this->dealChatImapWakeMailer->pokeMailboxesAfterServerChatWrite($lot);
        }

        Log::info('[arbiter] operator_signed_dispute_verdict', [
            'lot_id' => $lot->id,
            'action' => $action,
            'verdict' => $verdict,
            'disputes_closed' => $closedCount,
            'already_closed' => $alreadyClosed,
            'payout_success' => is_array($payout) ? ($payout['success'] ?? null) : null,
        ]);

        return [
            'verdict' => $verdict,
            'disputes_closed' => $closedCount,
            'already_closed' => $alreadyClosed,
            'body' => $body,
        ];
    }

    /**
     * @param  array{success: bool, error: ?string, crypto_tx_id: ?string, transaction_id: ?int}|null  $payout
     */
    private function resolutionTextForFavorBuyer(?array $payout): string
    {
        if (is_array($payout) && ! empty($payout['crypto_tx_id'])) {
            return 'Exchange operator (signed mail link): favor buyer. Seller escrow paid out to buyer address on file. Tx: '.$payout['crypto_tx_id'].'.';
        }

        return 'Exchange operator (signed mail link): favor buyer. Dispute closed.';
    }

    /**
     * @param  array{success: bool, error: ?string, crypto_tx_id: ?string, transaction_id: ?int}|null  $payout
     */
    /**
     * @param  array{success: bool, error: ?string, crypto_tx_id: ?string, transaction_id: ?int}|null  $payout
     */
    private function explorerUrlForOperatorPayout(ExchangeLot $lot, ?array $payout): ?string
    {
        $tx = is_array($payout) ? trim((string) ($payout['crypto_tx_id'] ?? '')) : '';
        if ($tx === '' || $tx === 'unknown') {
            return null;
        }

        try {
            $cryptoType = DealEscrowCryptoType::sellerCoreType($lot);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $environment = (bool) $lot->is_test
            ? ApiDeploymentEnvironment::SANDBOX
            : ApiDeploymentEnvironment::PRODUCTION;

        return CryptoTxExplorerUrl::forTx($cryptoType, $tx, $environment);
    }

    /**
     * @param  array{success: bool, error: ?string, crypto_tx_id: ?string, transaction_id: ?int}|null  $payout
     */
    private function operatorChatBody(ExchangeLot $lot, string $verdict, ?array $payout, bool $disputeWasAlreadyClosed): string
    {
        if ($verdict === 'favor_seller') {
            return 'Exchange operator decision: favor seller. The dispute for this lot is closed.';
        }

        $tx = is_array($payout) ? trim((string) ($payout['crypto_tx_id'] ?? '')) : '';
        if ($tx !== '') {
            $body = 'Exchange operator decision: favor buyer. Seller escrow paid out to buyer address on file. Crypto tx: '.$tx.'.';
            $explorerUrl = $this->explorerUrlForOperatorPayout($lot, $payout);
            if ($explorerUrl !== null) {
                $body .= ' Verify on-chain: '.$explorerUrl;
            }

            return $body;
        }

        if ($disputeWasAlreadyClosed) {
            return 'Exchange operator decision: favor buyer (dispute was already closed).';
        }

        return 'Exchange operator decision: favor buyer. The dispute for this lot is closed.';
    }

    private function enforceMaxMessagesPerDeal(int $lotId): void
    {
        $maxMessages = (int) config('deal.max_messages_per_deal', 1000);
        if ($maxMessages <= 0) {
            return;
        }
        $total = DealChatMessage::where('exchange_lot_id', $lotId)->count();
        if ($total <= $maxMessages) {
            return;
        }
        $excess = $total - $maxMessages;
        $idsToDelete = DealChatMessage::where('exchange_lot_id', $lotId)
            ->orderBy('id')
            ->limit($excess)
            ->pluck('id')
            ->all();
        if ($idsToDelete !== []) {
            DealChatMessage::whereIn('id', $idsToDelete)->delete();
        }
    }
}

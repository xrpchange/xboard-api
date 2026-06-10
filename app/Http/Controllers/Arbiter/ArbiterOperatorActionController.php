<?php

namespace App\Http\Controllers\Arbiter;

use App\Http\Controllers\Controller;
use App\Models\DealChatMessage;
use App\Models\DealDispute;
use App\Models\ExchangeLot;
use App\Services\Arbiter\ArbiterOperatorSignedActionService;
use App\Services\Deal\DealChatImapWakeMailer;
use App\Services\Deal\DealInternalMailRateLimitService;
use App\Services\Deal\DealReservationPayoutToBuyerService;
use App\Support\PublicHttpsUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Signed links from operator escalation mail (lot + action + expiry in URL).
 * reply: GET form + POST chat row; compensate_buyer: Core payout + close dispute; deny_buyer: close dispute favor seller.
 */
final class ArbiterOperatorActionController extends Controller
{
    public function __construct(
        private readonly DealChatImapWakeMailer $dealChatImapWakeMailer,
        private readonly DealInternalMailRateLimitService $dealInternalMailRateLimit,
        private readonly ArbiterOperatorSignedActionService $operatorSignedActions,
        private readonly DealReservationPayoutToBuyerService $reservationPayoutToBuyer,
    ) {}

    public function handle(Request $request, string $lot, string $action): View|RedirectResponse
    {
        Log::info('[arbiter] operator_mail_action', [
            'lot_id' => $lot,
            'action' => $action,
            'method' => $request->method(),
        ]);

        if ($request->isMethod('get')) {
            return match ($action) {
                'reply' => view('arbiter.operator_reply_form', [
                    'lotId' => $lot,
                    'action' => $action,
                    'formAction' => $this->secureRequestUrl($request),
                ]),
                'compensate_buyer', 'deny_buyer' => $this->operatorDecisionForm($request, $lot, $action),
                'block_buyer_internal_mail', 'block_seller_internal_mail' => view('arbiter.operator_block_internal_mail_form', [
                    'lotId' => $lot,
                    'action' => $action,
                    'participant' => $action === 'block_buyer_internal_mail' ? 'buyer' : 'seller',
                    'formAction' => $this->secureRequestUrl($request),
                ]),
                default => abort(404, 'Unknown operator action'),
            };
        }

        if ($request->isMethod('post')) {
            return match ($action) {
                'reply' => $this->storeOperatorReply($request, $lot),
                'compensate_buyer' => $this->applyCompensateBuyer($lot),
                'deny_buyer' => $this->applyOperatorDecision($lot, 'deny_buyer', 'favor_seller'),
                'block_buyer_internal_mail' => $this->blockParticipantInternalMail($lot, 'buyer'),
                'block_seller_internal_mail' => $this->blockParticipantInternalMail($lot, 'seller'),
                default => abort(405),
            };
        }

        abort(405);
    }

    private function operatorDecisionForm(Request $request, string $lot, string $action): View
    {
        $lotModel = ExchangeLot::query()->find((int) $lot);
        if ($lotModel === null) {
            abort(404, 'Lot not found');
        }

        $activeDispute = DealDispute::query()
            ->where('exchange_lot_id', $lotModel->id)
            ->whereIn('status', ['opened', 'in_review'])
            ->orderByDesc('id')
            ->first();

        if ($action === 'compensate_buyer') {
            $existing = $this->reservationPayoutToBuyer->findExistingForcedReservationPayout($lotModel);
            if ($existing !== null) {
                return view('arbiter.operator_decision_done', [
                    'lotId' => (string) $lotModel->id,
                    'transactionId' => $existing['transaction_id'] ?? $lotModel->primaryDealTransactionId(),
                    'action' => 'compensate_buyer',
                    'verdict' => 'favor_buyer',
                    'disputesClosed' => 0,
                    'alreadyClosed' => $activeDispute === null,
                    'body' => 'This lot was already compensated from seller escrow. No further payout was submitted.',
                    'cryptoTxId' => $existing['crypto_tx_id'] ?? null,
                    'alreadyCompensated' => true,
                ]);
            }
        }

        $meta = match ($action) {
            'compensate_buyer' => [
                'title' => 'Compensate buyer',
                'summary' => 'Pay the buyer from the seller escrow reservation (Core) to the crypto address on file for this deal, close the dispute with verdict favor buyer, and post the outcome to deal chat.',
                'confirmLabel' => 'Pay buyer from reservation and close dispute',
            ],
            'deny_buyer' => [
                'title' => 'Deny buyer',
                'summary' => 'Close the active dispute with verdict favor seller. Participants will see an operator decision in deal chat. This link does not move funds.',
                'confirmLabel' => 'Close dispute — favor seller',
            ],
            default => abort(404),
        };

        return view('arbiter.operator_decision_form', [
            'lotId' => (string) $lotModel->id,
            'transactionId' => $lotModel->primaryDealTransactionId(),
            'action' => $action,
            'title' => $meta['title'],
            'summary' => $meta['summary'],
            'confirmLabel' => $meta['confirmLabel'],
            'hasActiveDispute' => $activeDispute !== null,
            'formAction' => $this->secureRequestUrl($request),
        ]);
    }

    private function applyCompensateBuyer(string $lot): View
    {
        $lotModel = ExchangeLot::query()->find((int) $lot);
        if ($lotModel === null) {
            abort(404, 'Lot not found');
        }

        $result = $this->operatorSignedActions->compensateBuyer($lotModel);
        if (! $result['ok']) {
            return view('arbiter.operator_decision_failed', [
                'lotId' => (string) $lotModel->id,
                'transactionId' => $lotModel->primaryDealTransactionId(),
                'action' => 'compensate_buyer',
                'errorCode' => $result['payout']['error'] ?? 'payout_failed',
                'transactionIdAttempted' => $result['payout']['transaction_id'] ?? null,
            ]);
        }

        return view('arbiter.operator_decision_done', [
            'lotId' => (string) $lotModel->id,
            'transactionId' => $lotModel->primaryDealTransactionId(),
            'action' => 'compensate_buyer',
            'verdict' => $result['verdict'],
            'disputesClosed' => $result['disputes_closed'],
            'alreadyClosed' => $result['already_closed'],
            'body' => $result['body'],
            'cryptoTxId' => $result['payout']['crypto_tx_id'] ?? null,
            'alreadyCompensated' => (bool) ($result['already_compensated'] ?? false),
        ]);
    }

    private function applyOperatorDecision(string $lot, string $action, string $verdict): View
    {
        $lotModel = ExchangeLot::query()->find((int) $lot);
        if ($lotModel === null) {
            abort(404, 'Lot not found');
        }

        $result = $this->operatorSignedActions->applyDisputeVerdict($lotModel, $action, $verdict);

        return view('arbiter.operator_decision_done', [
            'lotId' => (string) $lotModel->id,
            'transactionId' => $lotModel->primaryDealTransactionId(),
            'action' => $action,
            'verdict' => $result['verdict'],
            'disputesClosed' => $result['disputes_closed'],
            'alreadyClosed' => $result['already_closed'],
            'body' => $result['body'],
            'cryptoTxId' => null,
        ]);
    }

    private function storeOperatorReply(Request $request, string $lot): RedirectResponse|View
    {
        $maxLen = max(1, (int) config('deal.max_message_length', 1000));
        $validator = Validator::make($request->all(), [
            'body' => ['required', 'string', 'max:'.$maxLen],
        ]);

        if ($validator->fails()) {
            return redirect($this->secureRequestUrl($request))
                ->withErrors($validator)
                ->withInput();
        }

        $body = trim((string) $validator->validated()['body']);
        if ($body === '') {
            return redirect($this->secureRequestUrl($request))
                ->withErrors(['body' => 'Message cannot be empty.'])
                ->withInput();
        }

        $lotModel = ExchangeLot::query()->find((int) $lot);
        if ($lotModel === null) {
            abort(404, 'Lot not found');
        }

        $tag = json_encode([
            't' => 'operator_mail_reply',
            'lot_id' => (string) $lotModel->id,
        ], JSON_UNESCAPED_UNICODE);

        DealChatMessage::create([
            'exchange_lot_id' => $lotModel->id,
            'sender_side' => 'exchange_operator',
            'from_email' => null,
            'to_email' => null,
            'body' => $body,
            'deal_tag' => $tag !== false ? $tag : null,
        ]);

        $this->enforceMaxMessagesPerDeal($lotModel->id);
        $lotModel->refresh();
        $this->dealChatImapWakeMailer->pokeMailboxesAfterServerChatWrite($lotModel);

        Log::info('[arbiter] operator_mail_reply_posted', [
            'lot_id' => $lotModel->id,
            'body_length' => mb_strlen($body),
        ]);

        return view('arbiter.operator_reply_sent', [
            'lotId' => (string) $lotModel->id,
        ]);
    }

    private function blockParticipantInternalMail(string $lot, string $side): View
    {
        $lotModel = ExchangeLot::query()->find((int) $lot);
        if ($lotModel === null) {
            abort(404, 'Lot not found');
        }

        $mailboxEmail = $side === 'buyer'
            ? trim((string) ($lotModel->buyer_lot_email ?? ''))
            : trim((string) ($lotModel->seller_lot_email ?? ''));

        if ($mailboxEmail === '' || ! $this->dealInternalMailRateLimit->exhaustForUtcDay($mailboxEmail)) {
            return view('arbiter.operator_block_internal_mail_failed', [
                'lotId' => (string) $lotModel->id,
                'participant' => $side,
            ]);
        }

        Log::info('[arbiter] operator_internal_mail_blocked', [
            'lot_id' => $lotModel->id,
            'participant' => $side,
            'mailbox_email' => $this->dealInternalMailRateLimit->normalizeMailboxEmail($mailboxEmail),
            'daily_limit' => $this->dealInternalMailRateLimit->dailyLimit(),
        ]);

        return view('arbiter.operator_block_internal_mail_done', [
            'lotId' => (string) $lotModel->id,
            'participant' => $side,
            'mailboxEmail' => $mailboxEmail,
            'dailyLimit' => $this->dealInternalMailRateLimit->dailyLimit(),
        ]);
    }

    /** Full URL with https scheme for non-local hosts (matches signed links in operator email). */
    private function secureRequestUrl(Request $request): string
    {
        $base = PublicHttpsUrl::upgradeMailBaseToHttps(rtrim($request->getSchemeAndHttpHost(), '/'));

        return $base.$request->getRequestUri();
    }

    /** Same cap as {@see \App\Http\Controllers\Api\ExchangeLotController::insertDealChatMessage}. */
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

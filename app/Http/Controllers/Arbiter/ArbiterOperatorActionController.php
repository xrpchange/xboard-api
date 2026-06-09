<?php

namespace App\Http\Controllers\Arbiter;

use App\Http\Controllers\Controller;
use App\Models\DealChatMessage;
use App\Models\ExchangeLot;
use App\Services\Deal\DealChatImapWakeMailer;
use App\Services\Deal\DealInternalMailRateLimitService;
use App\Support\PublicHttpsUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Signed links from operator escalation mail (lot + action + expiry in URL).
 * reply: GET shows a form; POST inserts a deal_chat_messages row visible in the app chat.
 */
final class ArbiterOperatorActionController extends Controller
{
    public function __construct(
        private readonly DealChatImapWakeMailer $dealChatImapWakeMailer,
        private readonly DealInternalMailRateLimitService $dealInternalMailRateLimit,
    ) {}

    public function handle(Request $request, string $lot, string $action): View|RedirectResponse
    {
        Log::info('[arbiter] operator_mail_action', [
            'lot_id' => $lot,
            'action' => $action,
            'method' => $request->method(),
        ]);

        if ($request->isMethod('get')) {
            if ($action === 'reply') {
                return view('arbiter.operator_reply_form', [
                    'lotId' => $lot,
                    'action' => $action,
                    'formAction' => $this->secureRequestUrl($request),
                ]);
            }
            if (in_array($action, ['block_buyer_internal_mail', 'block_seller_internal_mail'], true)) {
                return view('arbiter.operator_block_internal_mail_form', [
                    'lotId' => $lot,
                    'action' => $action,
                    'participant' => $action === 'block_buyer_internal_mail' ? 'buyer' : 'seller',
                    'formAction' => $this->secureRequestUrl($request),
                ]);
            }

            return view('arbiter.operator_action_ack', [
                'lotId' => $lot,
                'action' => $action,
            ]);
        }

        if ($request->isMethod('post') && $action === 'reply') {
            return $this->storeOperatorReply($request, $lot);
        }
        if ($request->isMethod('post') && $action === 'block_buyer_internal_mail') {
            return $this->blockParticipantInternalMail($lot, 'buyer');
        }
        if ($request->isMethod('post') && $action === 'block_seller_internal_mail') {
            return $this->blockParticipantInternalMail($lot, 'seller');
        }

        abort(405);
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

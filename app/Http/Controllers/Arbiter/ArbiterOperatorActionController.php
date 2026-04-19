<?php

namespace App\Http\Controllers\Arbiter;

use App\Http\Controllers\Controller;
use App\Models\DealChatMessage;
use App\Models\ExchangeLot;
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

            return view('arbiter.operator_action_ack', [
                'lotId' => $lot,
                'action' => $action,
            ]);
        }

        if ($request->isMethod('post') && $action === 'reply') {
            return $this->storeOperatorReply($request, $lot);
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

        Log::info('[arbiter] operator_mail_reply_posted', [
            'lot_id' => $lotModel->id,
            'body_length' => mb_strlen($body),
        ]);

        return view('arbiter.operator_reply_sent', [
            'lotId' => (string) $lotModel->id,
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

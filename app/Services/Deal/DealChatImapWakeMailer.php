<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Mail\DealChatImapWakeMailable;
use App\Models\ExchangeLot;
use App\Services\LotMailboxService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Deal chat is persisted in DB; clients wake IMAP IDLE on new mail in lot mailboxes, then refetch chat via API.
 * Any server-side-only chat write (no client SMTP) must poke those mailboxes or UIs stay stale.
 */
final class DealChatImapWakeMailer
{
    public function __construct(
        private readonly LotMailboxService $lotMailboxService,
    ) {}

    public function pokeMailboxesAfterServerChatWrite(ExchangeLot $lot): void
    {
        $lot->loadMissing(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);

        $sellerEscrow = $lot->fromCryptoCurrency ?? $lot->toCryptoCurrency ?? $lot->cryptoCurrency;
        $currency = $sellerEscrow?->code ?? ($lot->cryptoCurrency?->code ?? 'crypto');

        $sellerEmail = $this->lotMailboxService->getMailboxEmailForAddress((string) ($lot->wallet_address ?? ''), $currency);
        if (! $sellerEmail && ! empty($lot->seller_lot_email)) {
            $sellerEmail = trim((string) $lot->seller_lot_email);
        }
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));

        $targets = array_values(array_unique(array_filter([
            $sellerEmail !== '' ? $sellerEmail : null,
            $buyerEmail !== '' ? $buyerEmail : null,
        ], static fn ($v) => $v !== null && $v !== '')));

        if ($targets === []) {
            Log::debug('[deal-chat-imap-wake] no mailboxes to poke', ['lot_id' => $lot->id]);

            return;
        }

        foreach ($targets as $to) {
            try {
                Mail::send(new DealChatImapWakeMailable($to));
                Log::info('[deal-chat-imap-wake] sent', ['lot_id' => $lot->id, 'to' => $to]);
            } catch (\Throwable $e) {
                Log::warning('[deal-chat-imap-wake] send failed', [
                    'lot_id' => $lot->id,
                    'to' => $to,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

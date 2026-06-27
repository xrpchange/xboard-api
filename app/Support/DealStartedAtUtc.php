<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DealChatMessage;
use App\Models\ExchangeLot;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/** First `start_deal` tag time (UTC), with transaction created_at fallback. */
final class DealStartedAtUtc
{
    public static function forLot(ExchangeLot $lot): ?CarbonImmutable
    {
        $startTagRows = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereNotNull('deal_tag')
            ->orderBy('id')
            ->get(['deal_tag', 'created_at']);
        foreach ($startTagRows as $row) {
            $raw = trim((string) ($row->deal_tag ?? ''));
            if ($raw === '') {
                continue;
            }
            $tag = json_decode($raw, true);
            if (! is_array($tag) || trim((string) ($tag['t'] ?? '')) !== 'start_deal') {
                continue;
            }
            try {
                return CarbonImmutable::parse((string) $row->created_at)->utc();
            } catch (\Throwable) {
                break;
            }
        }

        $tx = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->orderBy('id')
            ->first(['created_at']);
        if ($tx !== null && $tx->created_at !== null) {
            try {
                return CarbonImmutable::parse((string) $tx->created_at)->utc();
            } catch (\Throwable) {
            }
        }

        return null;
    }
}

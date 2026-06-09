<?php

namespace App\Services\Deal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily cap on participant-initiated internal deal mail (lot mailbox SMTP), keyed by sender mailbox email.
 */
final class DealInternalMailRateLimitService
{
    private const LOG_TAG = 'deal-internal-mail-limit';

    public function dailyLimit(): int
    {
        return (int) config('deal.internal_mail_daily_limit', 300);
    }

    public function isEnabled(): bool
    {
        return $this->dailyLimit() > 0;
    }

    public function normalizeMailboxEmail(?string $email): ?string
    {
        $normalized = strtolower(trim((string) $email));
        if ($normalized === '' || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $normalized;
    }

    public function sentCountToday(string $email): int
    {
        $normalized = $this->normalizeMailboxEmail($email);
        if ($normalized === null) {
            return 0;
        }

        $row = DB::table('deal_internal_mail_daily_counters')
            ->where('email', $normalized)
            ->where('day', $this->utcDay())
            ->first();

        return $row !== null ? (int) $row->sent_count : 0;
    }

    /**
     * Reserve one send slot atomically. Returns false when the daily cap is reached.
     */
    public function reserveSendSlot(?string $senderLotMailboxEmail): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        $normalized = $this->normalizeMailboxEmail($senderLotMailboxEmail);
        if ($normalized === null) {
            Log::warning(self::LOG_TAG.' reserve skipped: invalid sender mailbox', [
                'email_len' => strlen(trim((string) $senderLotMailboxEmail)),
            ]);

            return false;
        }

        $limit = $this->dailyLimit();
        $day = $this->utcDay();

        return DB::transaction(function () use ($normalized, $day, $limit): bool {
            $row = DB::table('deal_internal_mail_daily_counters')
                ->where('email', $normalized)
                ->where('day', $day)
                ->lockForUpdate()
                ->first();

            if ($row !== null && (int) $row->sent_count >= $limit) {
                return false;
            }

            if ($row === null) {
                DB::table('deal_internal_mail_daily_counters')->insert([
                    'email' => $normalized,
                    'day' => $day,
                    'sent_count' => 1,
                    'last_sent_at' => now()->utc(),
                    'created_at' => now()->utc(),
                    'updated_at' => now()->utc(),
                ]);
            } else {
                DB::table('deal_internal_mail_daily_counters')
                    ->where('id', $row->id)
                    ->update([
                        'sent_count' => (int) $row->sent_count + 1,
                        'last_sent_at' => now()->utc(),
                        'updated_at' => now()->utc(),
                    ]);
            }

            return true;
        });
    }

    /**
     * Operator block until UTC midnight: pretend the sender already hit the daily cap.
     */
    public function exhaustForUtcDay(?string $senderLotMailboxEmail): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $normalized = $this->normalizeMailboxEmail($senderLotMailboxEmail);
        if ($normalized === null) {
            return false;
        }

        $limit = $this->dailyLimit();
        $day = $this->utcDay();
        $now = now()->utc();

        DB::transaction(function () use ($normalized, $day, $limit, $now): void {
            $row = DB::table('deal_internal_mail_daily_counters')
                ->where('email', $normalized)
                ->where('day', $day)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('deal_internal_mail_daily_counters')->insert([
                    'email' => $normalized,
                    'day' => $day,
                    'sent_count' => $limit,
                    'last_sent_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('deal_internal_mail_daily_counters')
                    ->where('id', $row->id)
                    ->update([
                        'sent_count' => $limit,
                        'last_sent_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
        });

        Log::info(self::LOG_TAG.' sender exhausted for utc day', [
            'email' => $normalized,
            'day' => $day,
            'limit' => $limit,
        ]);

        return true;
    }

    private function utcDay(): string
    {
        return now()->utc()->toDateString();
    }
}

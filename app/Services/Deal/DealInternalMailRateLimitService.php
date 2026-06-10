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

    /**
     * Normalize lot mailbox email for counter keying.
     *
     * Do not use FILTER_VALIDATE_EMAIL: RFC local-part length (64) rejects real lot mailboxes
     * (e.g. xmr_{primary} can exceed 200 characters in local_part).
     */
    public function normalizeMailboxEmail(?string $email): ?string
    {
        $normalized = strtolower(trim((string) $email));
        if ($normalized === '') {
            return null;
        }

        if (! str_contains($normalized, '@')) {
            return null;
        }

        [$localPart, $domain] = explode('@', $normalized, 2);
        $localPart = trim($localPart);
        $domain = trim($domain);
        if ($localPart === '' || $domain === '' || str_contains($localPart, '@') || str_contains($domain, '@')) {
            return null;
        }

        return $localPart.'@'.$domain;
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
     * True when the sender has already reached the daily cap (check only — does not increment).
     */
    public function isDailyLimitReached(?string $senderLotMailboxEmail): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $normalized = $this->normalizeMailboxEmail($senderLotMailboxEmail);
        if ($normalized === null) {
            return false;
        }

        return $this->sentCountToday($normalized) >= $this->dailyLimit();
    }

    /**
     * Count one successfully delivered internal deal mail after SMTP accepted the message.
     */
    public function recordSuccessfulSend(?string $senderLotMailboxEmail): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $normalized = $this->normalizeMailboxEmail($senderLotMailboxEmail);
        if ($normalized === null) {
            Log::warning(self::LOG_TAG.' record skipped: invalid sender mailbox', [
                'email_len' => strlen(trim((string) $senderLotMailboxEmail)),
            ]);

            return;
        }

        $limit = $this->dailyLimit();
        $day = $this->utcDay();

        DB::transaction(function () use ($normalized, $day, $limit): void {
            $row = DB::table('deal_internal_mail_daily_counters')
                ->where('email', $normalized)
                ->where('day', $day)
                ->lockForUpdate()
                ->first();

            if ($row !== null && (int) $row->sent_count >= $limit) {
                Log::warning(self::LOG_TAG.' record after cap (race)', [
                    'email' => $normalized,
                    'day' => $day,
                    'sent_count' => (int) $row->sent_count,
                    'limit' => $limit,
                ]);

                return;
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

    /**
     * Reset counter for support (today only). Returns rows deleted.
     */
    public function clearTodayForMailbox(?string $senderLotMailboxEmail): int
    {
        $normalized = $this->normalizeMailboxEmail($senderLotMailboxEmail);
        if ($normalized === null) {
            return 0;
        }

        return DB::table('deal_internal_mail_daily_counters')
            ->where('email', $normalized)
            ->where('day', $this->utcDay())
            ->delete();
    }

    private function utcDay(): string
    {
        return now()->utc()->toDateString();
    }
}

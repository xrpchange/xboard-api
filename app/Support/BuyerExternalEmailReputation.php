<?php

namespace App\Support;

use App\Models\ExchangeLot;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persistent buyer reputation keyed by external notification email (buyer_notification_email).
 */
final class BuyerExternalEmailReputation
{
    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $normalized = strtolower(trim($email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $normalized;
    }

    /**
     * @return array{completed_deals_count: int, bad_deals_count: int}|null
     */
    public static function countsForEmail(?string $email): ?array
    {
        $normalized = self::normalizeEmail($email);
        if ($normalized === null) {
            return null;
        }

        $row = DB::table('buyer_external_email_reputations')
            ->where('external_email', $normalized)
            ->first(['completed_deals_count', 'bad_deals_count']);

        if ($row === null) {
            return [
                'completed_deals_count' => 0,
                'bad_deals_count' => 0,
            ];
        }

        return [
            'completed_deals_count' => (int) ($row->completed_deals_count ?? 0),
            'bad_deals_count' => (int) ($row->bad_deals_count ?? 0),
        ];
    }

    public static function incrementCompletedDeal(?string $email): void
    {
        self::incrementCounter($email, 'completed_deals_count');
    }

    /**
     * Dispute closed with buyer claim rejected (operator deny_buyer or AI favor_seller).
     * Not incremented on operator compensate_buyer — that increments lot bad_deals_count instead.
     */
    public static function incrementBadDeal(?string $email): void
    {
        self::incrementCounter($email, 'bad_deals_count');
    }

    public static function incrementCompletedDealForTransaction(Transaction $transaction): void
    {
        $email = self::normalizeEmail($transaction->buyer_notification_email);
        if ($email === null && $transaction->exchange_lot_id !== null) {
            $lot = ExchangeLot::query()->find($transaction->exchange_lot_id);
            $email = self::normalizeEmail($lot?->buyer_notification_email);
        }
        self::incrementCompletedDeal($email);
    }

    public static function incrementBadDealForLot(ExchangeLot $lot): void
    {
        self::incrementBadDeal(self::normalizeEmail($lot->buyer_notification_email));
    }

    private static function incrementCounter(?string $email, string $column): void
    {
        $normalized = self::normalizeEmail($email);
        if ($normalized === null) {
            Log::info('[buyer-reputation] increment skipped: external email missing or invalid', [
                'column' => $column,
            ]);

            return;
        }

        if (! in_array($column, ['completed_deals_count', 'bad_deals_count'], true)) {
            throw new \InvalidArgumentException('Unsupported reputation column: '.$column);
        }

        $now = now()->utc();
        $inserted = DB::table('buyer_external_email_reputations')->insertOrIgnore([
            'external_email' => $normalized,
            'completed_deals_count' => 0,
            'bad_deals_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $updated = DB::table('buyer_external_email_reputations')
            ->where('external_email', $normalized)
            ->increment($column);

        if ($updated === 0 && $inserted === 0) {
            Log::warning('[buyer-reputation] increment failed', [
                'external_email' => $normalized,
                'column' => $column,
            ]);
        }
    }
}

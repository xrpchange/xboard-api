<?php

namespace App\Services;

use App\Models\ExchangeLot;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort Core cleanup when API removes a transactions row linked to a deal.
 */
class CoreReservationCleanupService
{
    public function __construct(
        private readonly EscrowTokenValidationService $escrowTokenValidation,
    ) {}

    /**
     * Delete all Core reservation rows (and linked fee state) for the API transaction id.
     * Failures are logged only; API transaction deletion must not be blocked.
     */
    public function deleteReservationsForTransaction(Transaction $transaction): void
    {
        $transactionId = (int) $transaction->id;
        if ($transactionId <= 0) {
            return;
        }

        $core = $this->resolveCoreUrlAndKeyForTransaction($transaction);
        if ($core === null) {
            Log::info('[transactions] Core delete-reservations-by-transaction-id skipped (no Core URL)', [
                'transaction_id' => $transactionId,
                'exchange_lot_id' => $transaction->exchange_lot_id,
            ]);

            return;
        }

        [$coreUrl, $coreKey] = $core;
        $endpoint = rtrim($coreUrl, '/').'/api/delete-reservations-by-transaction-id';

        try {
            $timeout = (int) config('services.core.timeout', 10);
            $req = Http::timeout($timeout);
            if ($coreKey !== '') {
                $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $resp = $req->post($endpoint, [
                'transaction_id' => $transactionId,
            ]);
            if ($resp->successful()) {
                Log::info('[transactions] Core delete-reservations-by-transaction-id OK', [
                    'transaction_id' => $transactionId,
                    'exchange_lot_id' => $transaction->exchange_lot_id,
                    'core_endpoint' => $endpoint,
                    'deleted' => $resp->json('data.deleted'),
                ]);
            } else {
                Log::warning('[transactions] Core delete-reservations-by-transaction-id failed', [
                    'transaction_id' => $transactionId,
                    'exchange_lot_id' => $transaction->exchange_lot_id,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                    'core_endpoint' => $endpoint,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[transactions] Core delete-reservations-by-transaction-id exception', [
                'transaction_id' => $transactionId,
                'exchange_lot_id' => $transaction->exchange_lot_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string}|null [coreUrl, coreKey]
     */
    public function resolveCoreUrlAndKeyForTransaction(Transaction $transaction): ?array
    {
        $lotId = $transaction->exchange_lot_id;
        if ($lotId === null) {
            return null;
        }

        $lot = ExchangeLot::query()->find($lotId);
        if ($lot === null) {
            return null;
        }

        $sellerToken = trim((string) ($lot->wallet_address ?? ''));
        if ($sellerToken !== '' && str_starts_with($sellerToken, '{')) {
            try {
                return $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerToken);
            } catch (\Throwable) {
                // fall through to lot environment defaults
            }
        }

        $isProduction = ! (bool) $lot->is_test;
        $coreUrl = $isProduction
            ? config('services.core_production.url')
            : config('services.core.url');
        if (empty($coreUrl)) {
            return null;
        }

        $coreKey = $isProduction
            ? (string) config('services.core_production.key')
            : (string) config('services.core.key');

        return [rtrim((string) $coreUrl, '/'), $coreKey];
    }
}

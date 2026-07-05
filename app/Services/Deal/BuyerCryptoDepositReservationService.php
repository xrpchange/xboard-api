<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Models\ExchangeLot;
use App\Models\Transaction;
use App\Services\EscrowTokenValidationService;
use App\Support\CryptoToFiatDealSupport;
use App\Support\DealEscrowCryptoType;
use App\Support\SellerEscrowReservedAmount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * After verified buyer_crypto_tx on crypto→fiat lots: lock inbound crypto on seller escrow token (Core reservation).
 */
final class BuyerCryptoDepositReservationService
{
    public function __construct(
        private readonly EscrowTokenValidationService $escrowTokenValidation,
    ) {}

    /**
     * @param  array<string, mixed>  $dealTagData
     * @return array{message:string,error_code:string,status:int}|null
     */
    public function lockDepositAfterVerifiedBuyerCryptoTx(ExchangeLot $lot, array $dealTagData): ?array
    {
        if (! CryptoToFiatDealSupport::isCryptoToFiatDeal($lot)) {
            return null;
        }

        $saleTransaction = $this->resolveSaleTransaction($lot, $dealTagData);
        if ($saleTransaction === null) {
            return [
                'message' => 'No pending sale transaction for buyer crypto deposit reservation.',
                'error_code' => 'no_pending_sale_tx',
                'status' => 400,
            ];
        }

        $sellerPublicJson = trim((string) ($lot->wallet_address ?? ''));
        if ($sellerPublicJson === '' || ! str_starts_with($sellerPublicJson, '{')) {
            return [
                'message' => 'Lot has no seller escrow token configured.',
                'error_code' => 'seller_escrow_token_missing',
                'status' => 422,
            ];
        }

        try {
            $cryptoType = DealEscrowCryptoType::sellerCoreType($lot);
        } catch (\InvalidArgumentException $e) {
            return [
                'message' => $e->getMessage(),
                'error_code' => 'invalid_lot_crypto',
                'status' => 422,
            ];
        }

        $reservedAmount = SellerEscrowReservedAmount::resolveForSellerEscrowLot($lot);
        if ($reservedAmount === null || $reservedAmount <= 0) {
            return [
                'message' => 'Cannot compute escrow reservation amount for buyer crypto deposit.',
                'error_code' => 'invalid_reservation_amount',
                'status' => 422,
            ];
        }

        try {
            [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerPublicJson);
        } catch (\Throwable $e) {
            Log::warning('[buyer-crypto-deposit] core URL resolve failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'message' => 'Core is unavailable for buyer deposit reservation.',
                'error_code' => 'core_unavailable',
                'status' => 502,
            ];
        }

        $timeout = (int) config('services.core.timeout', 10);
        $req = Http::timeout($timeout)->asJson();
        if ($coreKey !== '') {
            $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
        }

        $reservationPayload = [
            'exchange_lot_id' => $lot->id,
            'reserved_amount' => $reservedAmount,
            'escrow_token' => $sellerPublicJson,
            'crypto_type' => $cryptoType,
            'is_legal_entity' => false,
            'reservation_side' => 'seller',
        ];
        if (! empty($lot->buyer_escrow_token_hash)) {
            $reservationPayload['buyer_escrow_token_hash'] = $lot->buyer_escrow_token_hash;
        }
        if ((bool) $lot->aml) {
            $reservationPayload['aml_required'] = true;
        }

        $reservationResponse = $req->post(rtrim($coreUrl, '/').'/api/reservations', $reservationPayload);
        if (! $reservationResponse->successful()) {
            $body = $reservationResponse->json();
            $message = is_array($body) && isset($body['message']) ? (string) $body['message'] : $reservationResponse->body();
            $errorCode = is_array($body) && isset($body['error_code']) ? (string) $body['error_code'] : 'reservation_failed';

            Log::warning('[buyer-crypto-deposit] Core reservation failed', [
                'lot_id' => $lot->id,
                'transaction_id' => $saleTransaction->id,
                'status' => $reservationResponse->status(),
                'message' => $message,
                'error_code' => $errorCode,
            ]);

            return [
                'message' => $message,
                'error_code' => $errorCode,
                'status' => $reservationResponse->status() >= 500 ? 502 : $reservationResponse->status(),
            ];
        }

        try {
            $linkReq = Http::timeout($timeout)->asJson();
            if ($coreKey !== '') {
                $linkReq = $linkReq->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $linkReq->post(rtrim($coreUrl, '/').'/api/reservations/set-transaction-id', [
                'exchange_lot_id' => $lot->id,
                'transaction_id' => $saleTransaction->id,
                'escrow_token' => $sellerPublicJson,
                'crypto_type' => $cryptoType,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[buyer-crypto-deposit] set-transaction-id failed (non-blocking)', [
                'lot_id' => $lot->id,
                'transaction_id' => $saleTransaction->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $dealTagData
     */
    private function resolveSaleTransaction(ExchangeLot $lot, array $dealTagData): ?Transaction
    {
        $tagTxId = isset($dealTagData['tx_id']) ? (int) $dealTagData['tx_id'] : 0;
        if ($tagTxId > 0) {
            $byTag = Transaction::query()
                ->where('id', $tagTxId)
                ->where('exchange_lot_id', $lot->id)
                ->where('tx_type', 'sale')
                ->first();
            if ($byTag !== null) {
                return $byTag;
            }
        }

        return Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('tx_type', 'sale')
            ->where('payment_details_set_by', 'seller')
            ->orderByDesc('id')
            ->first();
    }
}

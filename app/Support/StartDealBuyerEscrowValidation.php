<?php

namespace App\Support;

use App\Models\ExchangeLot;
use App\Services\EscrowTokenValidationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * start_deal: buyer escrow token must match lot payment crypto; AML lots require Core reservation headroom.
 */
final class StartDealBuyerEscrowValidation
{
    public function __construct(
        private readonly EscrowTokenValidationService $escrowTokenValidation,
    ) {}

    /**
     * @return array{message:string,error_code:string,status:int}|null
     */
    public function validate(ExchangeLot $lot, string $buyerEscrowPublicPart, float $selectedAmount): ?array
    {
        $paysCrypto = self::resolveBuyerFirstLegPaysCrypto($lot);
        if ($paysCrypto === null) {
            return [
                'message' => 'Lot currency types are incomplete; cannot start deal.',
                'error_code' => 'lot_currency_types_incomplete',
                'status' => 422,
            ];
        }
        if (! $paysCrypto) {
            return null;
        }

        $requiresBuyerReservationGate = EscrowTokenOnlyCryptoDealRules::buyerFirstLegRequiresEscrowTokenServerPayout($lot);
        if (! $requiresBuyerReservationGate) {
            return null;
        }

        $trimmed = trim($buyerEscrowPublicPart);
        if ($trimmed === '' || ! str_starts_with($trimmed, '{')) {
            return [
                'message' => 'Valid buyer escrow_token (public JSON) is required to start this deal.',
                'error_code' => 'buyer_escrow_token_required',
                'status' => 422,
            ];
        }

        $sellerHash = strtolower(trim((string) ($lot->wallet_hash ?? '')));
        $buyerHash = DealEscrowPublicPart::tokenHashFromJson($trimmed);
        if ($sellerHash !== '' && $buyerHash !== null && hash_equals($sellerHash, $buyerHash)) {
            return [
                'message' => 'Cannot send message to yourself. This is your lot.',
                'error_code' => 'cannot_send_to_yourself',
                'status' => 400,
            ];
        }

        try {
            $lotType = DealEscrowCryptoType::buyerCoreType($lot);
        } catch (\InvalidArgumentException $e) {
            return [
                'message' => 'Lot has no buyer payment crypto configured.',
                'error_code' => 'lot_buyer_crypto_missing',
                'status' => 422,
            ];
        }

        $tokenType = DealEscrowPublicPart::coreCryptoTypeFromJson($trimmed);
        if ($tokenType === null || ! DealEscrowPublicPart::coreTypesCompatible($tokenType, $lotType)) {
            return [
                'message' => 'Buyer escrow token must match the lot payment currency ('.$lotType.').',
                'error_code' => 'buyer_escrow_token_crypto_mismatch',
                'status' => 422,
            ];
        }

        return $this->validateAmlReservationBalance($lot, $trimmed, $selectedAmount);
    }

    /**
     * @return array{message:string,error_code:string,status:int}|null
     */
    private function validateAmlReservationBalance(ExchangeLot $lot, string $buyerEscrowPublicPart, float $selectedAmount): ?array
    {
        $reservedAmount = SellerEscrowReservedAmount::resolveForBuyerEscrowLot($lot);
        if ($reservedAmount === null || $reservedAmount <= 0) {
            $reservedAmount = $selectedAmount;
        }
        if ($reservedAmount <= 0) {
            return [
                'message' => 'selected_amount is required for AML reservation check.',
                'error_code' => 'selected_amount_required',
                'status' => 422,
            ];
        }

        try {
            [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($buyerEscrowPublicPart);
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] start_deal AML preview: core URL resolve failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'message' => 'Core is unavailable for reservation check.',
                'error_code' => 'core_unavailable',
                'status' => 502,
            ];
        }

        $timeout = (int) config('services.core.timeout', 30);
        $req = Http::timeout($timeout)->asJson();
        if ($coreKey !== '') {
            $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
        }
        $requirementsPayload = [
            'escrow_token' => $buyerEscrowPublicPart,
            'reserved_amount' => $reservedAmount,
            'reservation_side' => 'buyer',
        ];
        if ((bool) $lot->aml) {
            $requirementsPayload['aml_required'] = true;
        }
        $resp = $req->post(rtrim($coreUrl, '/').'/api/escrow-tokens/reservation-requirements', $requirementsPayload);

        $json = $resp->json();
        if (! $resp->successful() || ! is_array($json)) {
            $message = is_array($json) ? (string) ($json['message'] ?? 'Reservation check failed') : 'Reservation check failed';

            return [
                'message' => $message,
                'error_code' => is_array($json) ? (string) ($json['error_code'] ?? 'reservation_check_failed') : 'reservation_check_failed',
                'status' => $resp->status() >= 500 ? 502 : 422,
            ];
        }

        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
        $available = isset($data['available']) ? (float) $data['available'] : null;
        $minToReserve = isset($data['min_to_reserve']) ? (float) $data['min_to_reserve'] : null;
        if ($available === null || $minToReserve === null) {
            return [
                'message' => 'Reservation check returned incomplete balance data.',
                'error_code' => 'reservation_check_incomplete',
                'status' => 502,
            ];
        }
        if ($available + 1e-12 < $minToReserve) {
            return [
                'message' => 'Insufficient escrow balance for AML deal reservation.',
                'error_code' => 'buyer_escrow_insufficient_for_reservation',
                'status' => 422,
            ];
        }

        $gasRequired = is_array($data['gas_required'] ?? null) ? $data['gas_required'] : null;
        if ($gasRequired !== null) {
            $gasMin = isset($gasRequired['min_amount']) ? (float) $gasRequired['min_amount'] : null;
            $gasCurrency = strtolower(trim((string) ($gasRequired['currency'] ?? '')));
            if ($gasMin !== null && $gasMin > 0 && $gasCurrency !== '') {
                $gasBalance = match ($gasCurrency) {
                    'xlm' => isset($data['xlm_balance']) ? (float) $data['xlm_balance'] : null,
                    'pol' => isset($data['pol_balance']) ? (float) $data['pol_balance'] : null,
                    'trx' => isset($data['trx_balance']) ? (float) $data['trx_balance'] : null,
                    default => null,
                };
                if ($gasBalance === null || $gasBalance + 1e-12 < $gasMin) {
                    return [
                        'message' => 'Insufficient gas on buyer escrow token for AML deal.',
                        'error_code' => 'buyer_escrow_gas_insufficient',
                        'status' => 422,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Uses {@see DealBuyerFirstLeg} only (no legacy to_fiat / Monero overrides).
     */
    private static function resolveBuyerFirstLegPaysCrypto(ExchangeLot $lot): ?bool
    {
        return DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot);
    }
}

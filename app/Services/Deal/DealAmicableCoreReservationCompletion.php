<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Models\ExchangeLot;
use App\Services\EscrowTokenValidationService;
use App\Support\CryptoToFiatDealSupport;
use App\Support\DealSaleTransactionCompletion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mandatory Core reservation completion (release hold + collect service fee + AML fee rows)
 * when a terminal amicable deal tag is accepted — before the tag is persisted in chat.
 */
final class DealAmicableCoreReservationCompletion
{
    public function __construct(
        private readonly EscrowTokenValidationService $escrowTokenValidation,
    ) {}

    /**
     * @param  array<string, mixed>  $dealTagData
     * @return array{ok:bool,skipped?:bool,message?:string,error_code?:string,http_status?:int}
     */
    public function completeBeforeAmicableChat(ExchangeLot $lot, Request $request, array $dealTagData, string $reason): array
    {
        if (DealSaleTransactionCompletion::dealTagIsBuyerCompletedForLot($dealTagData, $lot)) {
            return $this->completeAfterBuyer($lot, $request, $reason);
        }
        if (DealSaleTransactionCompletion::dealTagIsSellerTimeoutCompletionForLot($dealTagData, $lot)) {
            return $this->completeAfterSellerTimeout($lot, $request, $reason);
        }

        return ['ok' => true, 'skipped' => true];
    }

    /**
     * @return array{ok:bool,message?:string,error_code?:string,http_status?:int}
     */
    private function completeAfterBuyer(ExchangeLot $lot, Request $request, string $reason): array
    {
        $lot->loadMissing(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);

        $sellerPublicForCompletion = CryptoToFiatDealSupport::sellerEscrowPublicJsonForBuyerAmicableCompletion($lot);
        if ($sellerPublicForCompletion !== null) {
            Log::info('[exchange-lots] buyer completion: release seller escrow reservation', [
                'lot_id' => $lot->id,
                'reason' => $reason,
                'crypto_to_fiat' => CryptoToFiatDealSupport::buyerCryptoFirstLegCryptoToFiat($lot),
                'fiat_to_crypto' => CryptoToFiatDealSupport::buyerFiatFirstLegFiatToCrypto($lot),
            ]);

            return $this->postCompleteReservationSeller($lot, $sellerPublicForCompletion, $reason);
        }
        if (CryptoToFiatDealSupport::buyerCryptoFirstLegCryptoToFiat($lot)
            || CryptoToFiatDealSupport::buyerFiatFirstLegFiatToCrypto($lot)) {
            return [
                'ok' => false,
                'message' => 'Seller escrow token is missing on this lot; cannot release reservation on completion.',
                'error_code' => 'seller_escrow_token_missing_on_lot',
                'http_status' => 422,
            ];
        }

        $buyerToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        if (! is_string($buyerToken) || trim($buyerToken) === '') {
            return [
                'ok' => false,
                'message' => 'Buyer escrow token is required to release reservation on deal completion.',
                'error_code' => 'escrow_token_required_for_completion',
                'http_status' => 422,
            ];
        }

        return $this->postCompleteReservationByBuyer($lot, $buyerToken, $reason);
    }

    /**
     * @return array{ok:bool,message?:string,error_code?:string,http_status?:int}
     */
    private function completeAfterSellerTimeout(ExchangeLot $lot, Request $request, string $reason): array
    {
        $sellerEscrowToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        if (! is_string($sellerEscrowToken) || trim($sellerEscrowToken) === '') {
            return [
                'ok' => false,
                'message' => 'Seller escrow token is required to release reservation on deal completion.',
                'error_code' => 'escrow_token_required_for_completion',
                'http_status' => 422,
            ];
        }

        return $this->postCompleteReservationSeller($lot, $sellerEscrowToken, $reason);
    }

    /**
     * @return array{ok:bool,message?:string,error_code?:string,http_status?:int}
     */
    private function postCompleteReservationSeller(ExchangeLot $lot, string $sellerEscrowToken, string $reason): array
    {
        $lot->loadMissing(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);
        [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerEscrowToken);
        $cryptoType = $this->resolveCryptoType($lot, $sellerEscrowToken);
        $coreEndpoint = rtrim($coreUrl, '/').'/api/complete-reservation';

        return $this->postReservationCompletion(
            $lot,
            $reason,
            $coreUrl,
            $coreKey,
            $coreEndpoint,
            'complete-reservation',
            $cryptoType,
            [
                'exchange_lot_id' => (string) $lot->id,
                'escrow_token' => $sellerEscrowToken,
                'crypto_type' => $cryptoType,
                'aml_required' => (bool) $lot->aml,
            ],
        );
    }

    /**
     * @return array{ok:bool,message?:string,error_code?:string,http_status?:int}
     */
    private function postCompleteReservationByBuyer(ExchangeLot $lot, string $buyerToken, string $reason): array
    {
        $lot->loadMissing(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);
        [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($buyerToken);
        $cryptoType = $this->resolveCryptoType($lot, $buyerToken);
        $coreEndpoint = rtrim($coreUrl, '/').'/api/complete-reservation-by-buyer';

        return $this->postReservationCompletion(
            $lot,
            $reason,
            $coreUrl,
            $coreKey,
            $coreEndpoint,
            'complete-reservation-by-buyer',
            $cryptoType,
            [
                'exchange_lot_id' => (string) $lot->id,
                'escrow_token' => $buyerToken,
                'crypto_type' => $cryptoType,
                'aml_required' => (bool) $lot->aml,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message?:string,error_code?:string,http_status?:int}
     */
    private function postReservationCompletion(
        ExchangeLot $lot,
        string $reason,
        string $coreUrl,
        string $coreKey,
        string $coreEndpoint,
        string $endpointLabel,
        string $cryptoType,
        array $payload,
    ): array {
        $timeout = $this->completionTimeoutSeconds($coreUrl);
        Log::info('[exchange-lots] Core '.$endpointLabel.' start (amicable completion)', [
            'lot_id' => $lot->id,
            'reason' => $reason,
            'core_endpoint' => $coreEndpoint,
            'timeout_seconds' => $timeout,
            'crypto_type' => $cryptoType,
        ]);

        try {
            $startedAt = microtime(true);
            $resp = $this->coreHttpClient($coreKey, $timeout)->post($coreEndpoint, $payload);

            return $this->interpretCoreCompletionResponse(
                $resp,
                $lot->id,
                $endpointLabel,
                $reason,
                $startedAt,
            );
        } catch (\Throwable $e) {
            if ($this->isCoreTransportFailure($e)) {
                $verified = $this->verifyCompletionAfterTransportFailure(
                    $lot,
                    $reason,
                    $coreKey,
                    $coreEndpoint,
                    $endpointLabel,
                    $timeout,
                    $payload,
                    $e,
                );
                if ($verified !== null) {
                    return $verified;
                }
            }

            Log::warning('[exchange-lots] Core '.$endpointLabel.' exception (amicable completion)', [
                'lot_id' => $lot->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $this->isCoreTransportFailure($e)
                    ? 'Core did not respond in time while finalizing the reservation. If the fee was already collected, confirm completion again.'
                    : 'Failed to finalize escrow reservation via Core.',
                'error_code' => $this->isCoreTransportFailure($e)
                    ? 'core_reservation_completion_timeout'
                    : 'core_reservation_completion_failed',
                'http_status' => 502,
            ];
        }
    }

    /**
     * After a transport timeout the first Core request may still finish (XMR fee transfer).
     * A follow-up POST is idempotent: 404 "matching reservation … not found" means completion already ran.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,message?:string,error_code?:string,http_status?:int}|null
     */
    private function verifyCompletionAfterTransportFailure(
        ExchangeLot $lot,
        string $reason,
        string $coreKey,
        string $coreEndpoint,
        string $endpointLabel,
        int $timeout,
        array $payload,
        \Throwable $original,
    ): ?array {
        Log::warning('[exchange-lots] Core '.$endpointLabel.' transport failure; verifying completion state', [
            'lot_id' => $lot->id,
            'reason' => $reason,
            'error' => $original->getMessage(),
        ]);

        try {
            $startedAt = microtime(true);
            $resp = $this->coreHttpClient($coreKey, $timeout)->post($coreEndpoint, $payload);
            $result = $this->interpretCoreCompletionResponse(
                $resp,
                $lot->id,
                $endpointLabel,
                $reason,
                $startedAt,
            );
            if ($result['ok'] ?? false) {
                Log::info('[exchange-lots] Core '.$endpointLabel.' OK after transport failure (verify)', [
                    'lot_id' => $lot->id,
                    'reason' => $reason,
                ]);

                return $result;
            }
        } catch (\Throwable $verifyError) {
            Log::warning('[exchange-lots] Core '.$endpointLabel.' verify after transport failure failed', [
                'lot_id' => $lot->id,
                'reason' => $reason,
                'error' => $verifyError->getMessage(),
            ]);
        }

        return null;
    }

    private function coreHttpClient(string $coreKey, int $timeout): \Illuminate\Http\Client\PendingRequest
    {
        $req = Http::timeout($timeout);
        if ($coreKey !== '') {
            $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
        }

        return $req;
    }

    private function completionTimeoutSeconds(string $coreUrl): int
    {
        $configKey = $this->isProductionCoreUrl($coreUrl) ? 'services.core_production' : 'services.core';

        return max(10, (int) config(
            $configKey.'.completion_timeout',
            config($configKey.'.payout_timeout', 150)
        ));
    }

    private function isProductionCoreUrl(string $coreUrl): bool
    {
        return ! preg_match('#://sandbox\.#i', $coreUrl)
            && ! preg_match('#://dev\.api\.core\.#i', $coreUrl);
    }

    private function isCoreTransportFailure(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return str_contains($e->getMessage(), 'cURL error 28')
            || str_contains($e->getMessage(), 'Operation timed out');
    }

    /**
     * @return array{ok:bool,message?:string,error_code?:string,http_status?:int}
     */
    private function interpretCoreCompletionResponse(
        Response $resp,
        int|string $lotId,
        string $endpointLabel,
        string $reason,
        float $startedAt,
    ): array {
        $responseBody = $resp->json();
        $responseBodyPreview = is_array($responseBody)
            ? json_encode($responseBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $resp->body();
        if (is_string($responseBodyPreview) && strlen($responseBodyPreview) > 500) {
            $responseBodyPreview = substr($responseBodyPreview, 0, 500).'...';
        }
        $tookMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($this->coreCompletionAlreadyDone($resp)) {
            Log::info('[exchange-lots] Core '.$endpointLabel.' OK (already completed / idempotent)', [
                'lot_id' => $lotId,
                'reason' => $reason,
                'took_ms' => $tookMs,
                'status' => $resp->status(),
                'core_body_preview' => $responseBodyPreview,
            ]);

            return ['ok' => true];
        }

        if ($resp->successful()) {
            Log::info('[exchange-lots] Core '.$endpointLabel.' OK (amicable completion)', [
                'lot_id' => $lotId,
                'reason' => $reason,
                'took_ms' => $tookMs,
                'core_body_preview' => $responseBodyPreview,
            ]);

            return ['ok' => true];
        }

        Log::warning('[exchange-lots] Core '.$endpointLabel.' failed (amicable completion)', [
            'lot_id' => $lotId,
            'reason' => $reason,
            'took_ms' => $tookMs,
            'status' => $resp->status(),
            'body' => $responseBodyPreview,
        ]);

        $message = is_array($responseBody) ? (string) ($responseBody['message'] ?? '') : '';
        if ($message === '') {
            $message = 'Core reservation completion failed.';
        }

        return [
            'ok' => false,
            'message' => $message,
            'error_code' => is_array($responseBody) ? (string) ($responseBody['error_code'] ?? 'core_reservation_completion_failed') : 'core_reservation_completion_failed',
            'http_status' => $resp->status() >= 400 && $resp->status() < 600 ? $resp->status() : 502,
        ];
    }

    private function coreCompletionAlreadyDone(Response $resp): bool
    {
        if ($resp->successful()) {
            return false;
        }
        if ($resp->status() !== 404) {
            return false;
        }
        $body = $resp->json();
        if (! is_array($body)) {
            return false;
        }
        $message = strtolower((string) ($body['message'] ?? ''));

        return str_contains($message, 'not found')
            || str_contains($message, 'matching reservation');
    }

    private function resolveCryptoType(ExchangeLot $lot, string $escrowTokenJson): string
    {
        $fromJson = $this->cryptoTypeFromEscrowTokenJson($escrowTokenJson);
        if ($fromJson !== null && $fromJson !== '') {
            return $fromJson;
        }

        return strtolower((string) ($lot->cryptoCurrency?->code ?? 'xmr'));
    }

    private function cryptoTypeFromEscrowTokenJson(string $escrowTokenJson): ?string
    {
        $decoded = json_decode($escrowTokenJson, true);
        if (! is_array($decoded) || ! isset($decoded['currency'])) {
            return null;
        }
        $raw = trim((string) $decoded['currency']);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(USDT|USDC|DAI)_(STELLAR|POLYGON|TRON)$/i', $raw, $m)) {
            return strtolower($m[1].'_'.$m[2]);
        }
        $currencyUpper = strtoupper($raw);

        return match ($currencyUpper) {
            'XRP' => 'xrp',
            'BTC' => 'btc',
            'XMR' => 'xmr',
            'MATIC', 'POLYGON' => 'polygon',
            default => strtolower($raw),
        };
    }
}

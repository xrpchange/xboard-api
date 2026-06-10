<?php

namespace App\Services\Deal;

use App\Mail\HighDeliveryPriority;
use App\Models\DealChatMessage;
use App\Models\ExchangeLot;
use App\Models\Transaction;
use App\Services\DealAmlAdminAlertService;
use App\Services\DealAmlPersistenceService;
use App\Services\EscrowTokenValidationService;
use App\Services\LotMailboxService;
use App\Support\DealEscrowCryptoType;
use App\Support\SellerEscrowReservedAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Pay buyer from seller escrow reservation via Core (API key, no client escrow token).
 * Used by AI arbiter auto-payout and operator signed compensate_buyer link.
 */
class DealReservationPayoutToBuyerService
{
    public function __construct(
        private readonly EscrowTokenValidationService $escrowTokenValidation,
        private readonly DealAmlPersistenceService $dealAmlPersistence,
        private readonly DealAmlAdminAlertService $dealAmlAdminAlert,
        private readonly LotMailboxService $lotMailboxService,
        private readonly DealChatImapWakeMailer $dealChatImapWakeMailer,
    ) {}

    /**
     * @return array{success: bool, error: ?string, crypto_tx_id: ?string, transaction_id: ?int}
     */
    public function execute(ExchangeLot $lot, string $payoutContext): array
    {
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return ['success' => false, 'error' => 'no_buyer', 'crypto_tx_id' => null, 'transaction_id' => null];
        }

        try {
            /** @var array{success: bool, error: ?string, crypto_tx_id: ?string, transaction_id: ?int} $out */
            $out = DB::transaction(function () use ($lot, $buyerEmail, $payoutContext): array {
                $transaction = Transaction::query()
                    ->where('exchange_lot_id', $lot->id)
                    ->where('buyer_lot_email', $buyerEmail)
                    ->where('tx_type', 'sale')
                    ->where('status', 'pending')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($transaction === null) {
                    return ['success' => false, 'error' => 'no_pending_tx', 'crypto_tx_id' => null, 'transaction_id' => null];
                }

                if (! empty($transaction->seller_crypto_payout_tx_hash)) {
                    return [
                        'success' => true,
                        'error' => null,
                        'crypto_tx_id' => (string) $transaction->seller_crypto_payout_tx_hash,
                        'transaction_id' => (int) $transaction->id,
                    ];
                }

                $dest = trim((string) ($transaction->crypto_address ?? ''));
                if ($dest === '') {
                    return ['success' => false, 'error' => 'buyer_crypto_address_missing', 'crypto_tx_id' => null, 'transaction_id' => (int) $transaction->id];
                }

                try {
                    $cryptoType = DealEscrowCryptoType::sellerCoreType($lot);
                } catch (\InvalidArgumentException $e) {
                    return ['success' => false, 'error' => $e->getMessage(), 'crypto_tx_id' => null, 'transaction_id' => (int) $transaction->id];
                }

                $amount = SellerEscrowReservedAmount::resolve($lot, $cryptoType);
                if ($amount === null || $amount <= 0) {
                    return ['success' => false, 'error' => 'invalid_payout_amount', 'crypto_tx_id' => null, 'transaction_id' => (int) $transaction->id];
                }

                [$coreUrl, $coreKey] = $this->resolveCoreUrlAndKey($lot);
                $timeout = (int) config('services.core.payout_timeout', 120);
                $payload = [
                    'exchange_lot_id' => (string) $lot->id,
                    'laravel_transaction_id' => $transaction->id,
                    'destination_address' => $dest,
                    'amount' => $amount,
                    'crypto_type' => $cryptoType,
                    'native_amount' => 0.0,
                    'aml_required' => (bool) $lot->aml,
                ];
                $payoutNetwork = DealEscrowCryptoType::coreNetworkFromCryptoType($cryptoType);
                if ($payoutNetwork !== null) {
                    $payload['network'] = $payoutNetwork;
                }

                $req = Http::timeout($timeout)->asJson();
                if ($coreKey !== '') {
                    $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
                }
                $endpoint = rtrim($coreUrl, '/').'/api/deal-payout-to-buyer-from-reservation';
                Log::info('[deal-payout] reservation_payout_to_buyer: core start', [
                    'lot_id' => $lot->id,
                    'transaction_id' => $transaction->id,
                    'endpoint' => $endpoint,
                    'payout_context' => $payoutContext,
                ]);
                $resp = $req->post($endpoint, $payload);
                $responseBody = $resp->json();
                $sellerEscrowTokenForAml = trim((string) ($lot->wallet_address ?? ''));

                if (! $resp->successful()) {
                    $body = is_array($responseBody) ? $responseBody : [];
                    $msg = $this->formatCoreHttpError($resp->status(), $body, (string) $resp->body());
                    Log::warning('[deal-payout] reservation_payout_to_buyer: core non-success', [
                        'lot_id' => $lot->id,
                        'transaction_id' => $transaction->id,
                        'endpoint' => $endpoint,
                        'payout_context' => $payoutContext,
                        'core_http_status' => $resp->status(),
                        'error_code' => is_array($body) ? ($body['error_code'] ?? null) : null,
                        'message' => $msg,
                    ]);
                    $this->persistPayoutAmlAndAlertOperatorIfNeeded(
                        $lot,
                        $transaction,
                        $payoutContext,
                        'seller',
                        $sellerEscrowTokenForAml,
                        $cryptoType,
                        $coreUrl,
                        $coreKey,
                        $resp->status(),
                        $body !== [] ? $body : ['raw' => (string) $resp->body()],
                    );

                    return [
                        'success' => false,
                        'error' => 'core_http_'.$resp->status().':'.$msg,
                        'crypto_tx_id' => null,
                        'transaction_id' => (int) $transaction->id,
                    ];
                }

                $body = is_array($responseBody) ? $responseBody : [];
                $cryptoTxId = '';
                if (isset($body['data']['transaction_id'])) {
                    $cryptoTxId = trim((string) $body['data']['transaction_id']);
                }
                if ($cryptoTxId === '') {
                    $cryptoTxId = 'unknown';
                }

                $transaction->seller_crypto_payout_tx_hash = $cryptoTxId;
                $transaction->status = 'completed';
                $this->persistPayoutAmlAndAlertOperatorIfNeeded(
                    $lot,
                    $transaction,
                    $payoutContext,
                    'seller',
                    $sellerEscrowTokenForAml,
                    $cryptoType,
                    $coreUrl,
                    $coreKey,
                    $resp->status(),
                    $body,
                );

                $this->tryCompleteSellerReservationAfterPayout(
                    $lot,
                    $sellerEscrowTokenForAml,
                    $cryptoType,
                    $coreUrl,
                    $coreKey,
                    $payoutContext,
                );

                $tagJson = json_encode([
                    't' => 'deal_completed_seller',
                    'lot_id' => (int) $lot->id,
                    'crypto_payout_tx' => $cryptoTxId,
                    'laravel_transaction_id' => $transaction->id,
                    'arbiter_reservation_payout' => true,
                    'payout_context' => $payoutContext,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                try {
                    $this->sendDealEmail(
                        to: $buyerEmail,
                        subject: 'Deal completed',
                        body: 'Seller escrow paid out to buyer address on file (reservation execution). Deal completed.',
                        tagJson: $tagJson,
                    );
                } catch (\Throwable $e) {
                    Log::warning('[deal-payout] reservation_payout_to_buyer: mailbox event send failed', [
                        'lot_id' => $lot->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $sellerEmailForChat = $this->lotMailboxService->getMailboxEmailForAddress(
                    $lot->wallet_address ?? '',
                    $this->sellerMailboxCryptoCode($lot),
                ) ?? $lot->seller_lot_email;

                $this->insertDealChatMessage(
                    $lot,
                    'seller',
                    $sellerEmailForChat ?? config('mail.from.address', 'noreply@xdexboard.com'),
                    $buyerEmail,
                    'Seller escrow paid out to buyer address on file (reservation execution). Deal completed.',
                    $tagJson,
                );

                $lot->refresh();
                $this->dealChatImapWakeMailer->pokeMailboxesAfterServerChatWrite($lot);

                return [
                    'success' => true,
                    'error' => null,
                    'crypto_tx_id' => $cryptoTxId,
                    'transaction_id' => (int) $transaction->id,
                ];
            });

            return $out;
        } catch (\Throwable $e) {
            Log::warning('[deal-payout] reservation_payout_to_buyer: failed', [
                'lot_id' => $lot->id,
                'payout_context' => $payoutContext,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'crypto_tx_id' => null, 'transaction_id' => null];
        }
    }

    private function tryCompleteSellerReservationAfterPayout(
        ExchangeLot $lot,
        string $sellerEscrowToken,
        string $cryptoType,
        string $coreUrl,
        string $coreKey,
        string $payoutContext,
    ): void {
        $sellerEscrowToken = trim($sellerEscrowToken);
        if ($sellerEscrowToken === '') {
            Log::info('[deal-payout] complete-reservation skipped (no seller escrow token on lot)', [
                'lot_id' => $lot->id,
                'payout_context' => $payoutContext,
            ]);

            return;
        }

        try {
            $req = Http::timeout((int) config('services.core.timeout', 10));
            if ($coreKey !== '') {
                $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $resp = $req->post(rtrim($coreUrl, '/').'/api/complete-reservation', [
                'exchange_lot_id' => (string) $lot->id,
                'escrow_token' => $sellerEscrowToken,
                'crypto_type' => $cryptoType,
                'aml_required' => (bool) $lot->aml,
            ]);
            if ($resp->successful()) {
                Log::info('[deal-payout] complete-reservation OK', [
                    'lot_id' => $lot->id,
                    'payout_context' => $payoutContext,
                ]);
            } else {
                Log::warning('[deal-payout] complete-reservation non-success', [
                    'lot_id' => $lot->id,
                    'payout_context' => $payoutContext,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[deal-payout] complete-reservation failed', [
                'lot_id' => $lot->id,
                'payout_context' => $payoutContext,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveCoreUrlAndKey(ExchangeLot $lot): array
    {
        $raw = trim((string) ($lot->wallet_address ?? ''));
        if ($raw !== '' && str_starts_with($raw, '{')) {
            try {
                return $this->escrowTokenValidation->getCoreUrlAndKeyForToken($raw);
            } catch (\Throwable) {
                // fall through to lot environment defaults
            }
        }

        $isProduction = ! (bool) $lot->is_test;
        $coreUrl = $isProduction
            ? config('services.core_production.url')
            : config('services.core.url');
        $coreKey = $isProduction
            ? (string) config('services.core_production.key')
            : (string) config('services.core.key');

        return [rtrim((string) $coreUrl, '/'), $coreKey];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function formatCoreHttpError(int $status, array $body, string $rawBody): string
    {
        if ($status === 502 || $status === 504) {
            return 'Core gateway error (no JSON body). Check Core/nginx logs and PHP-FPM stderr volume.';
        }
        if (isset($body['message']) && is_string($body['message']) && trim($body['message']) !== '') {
            return trim($body['message']);
        }
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($rawBody)) ?? '');
        if (strlen($plain) > 240) {
            $plain = substr($plain, 0, 240).'...';
        }

        return $plain !== '' ? $plain : 'unknown';
    }

    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    private function persistPayoutAmlAndAlertOperatorIfNeeded(
        ExchangeLot $lot,
        Transaction $transaction,
        string $payoutContext,
        string $screeningSide,
        string $escrowTokenJson,
        string $cryptoType,
        string $coreUrl,
        string $coreKey,
        int $httpStatus,
        ?array $responseBody,
    ): void {
        $this->dealAmlPersistence->handlePayoutCoreResponse(
            $lot,
            $transaction,
            $payoutContext,
            $screeningSide,
            $escrowTokenJson,
            $cryptoType,
            null,
            $httpStatus,
            $responseBody,
        );

        if (! is_array($responseBody) || (string) ($responseBody['error_code'] ?? '') !== 'AML_PROVIDER_UNAVAILABLE') {
            return;
        }

        $this->dealAmlAdminAlert->notifyProviderFailure(
            (int) $lot->id,
            (int) $transaction->id,
            $payoutContext,
            $screeningSide,
            $coreUrl,
            $coreKey,
            $httpStatus,
            $responseBody,
        );
    }

    private function sellerMailboxCryptoCode(ExchangeLot $lot): string
    {
        $lot->loadMissing(['fromCryptoCurrency', 'toCryptoCurrency', 'cryptoCurrency']);
        $c = $lot->fromCryptoCurrency ?? $lot->toCryptoCurrency ?? $lot->cryptoCurrency;

        return $c?->code ?? 'crypto';
    }

    private function sendDealEmail(string $to, string $subject, string $body, ?string $tagJson): void
    {
        $fromAddress = config('mail.from.address', 'noreply@xdexboard.com');
        $fromName = config('mail.from.name', 'XBoard');
        Mail::raw($body, function ($message) use ($to, $subject, $fromAddress, $fromName, $tagJson) {
            $message->to($to);
            $message->subject($subject);
            $message->from($fromAddress, $fromName);
            if ($tagJson !== null) {
                $message->getHeaders()->addTextHeader('X-XBoard-Deal-Tag', $tagJson);
            }
            HighDeliveryPriority::apply($message);
        });
    }

    private function insertDealChatMessage(
        ExchangeLot $lot,
        string $senderSide,
        ?string $fromEmail,
        ?string $toEmail,
        string $body,
        ?string $dealTag,
    ): void {
        DealChatMessage::create([
            'exchange_lot_id' => $lot->id,
            'sender_side' => $senderSide,
            'from_email' => $fromEmail,
            'to_email' => $toEmail,
            'body' => $body,
            'deal_tag' => $dealTag,
        ]);

        $maxMessages = (int) config('deal.max_messages_per_deal', 1000);
        if ($maxMessages <= 0) {
            return;
        }
        $total = DealChatMessage::where('exchange_lot_id', $lot->id)->count();
        if ($total <= $maxMessages) {
            return;
        }
        $excess = $total - $maxMessages;
        $idsToDelete = DealChatMessage::where('exchange_lot_id', $lot->id)
            ->orderBy('id')
            ->limit($excess)
            ->pluck('id')
            ->all();
        if ($idsToDelete !== []) {
            DealChatMessage::whereIn('id', $idsToDelete)->delete();
        }
    }
}

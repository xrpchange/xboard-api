<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Mail\HighDeliveryPriority;
use App\Models\DealChatMessage;
use App\Models\DealCryptoPayoutJob;
use App\Models\DealDispute;
use App\Models\ExchangeLot;
use App\Models\Transaction;
use App\Services\DealAmlAdminAlertService;
use App\Services\DealAmlPersistenceService;
use App\Services\EscrowTokenValidationService;
use App\Services\LotMailboxService;
use App\Support\DealEscrowCryptoType;
use App\Support\DealPayoutDestinationAddressValidation;
use App\Support\EscrowTokenOnlyCryptoDealRules;
use App\Support\MoneroUnlockedBalanceError;
use App\Support\SellerEscrowReservedAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Seller escrow crypto payout to buyer (deal-payout-to-buyer-address) with async retry for XMR maturity.
 */
final class SellerCryptoPayoutService
{
    public function __construct(
        private readonly EscrowTokenValidationService $escrowTokenValidation,
        private readonly DealAmlPersistenceService $dealAmlPersistence,
        private readonly DealAmlAdminAlertService $dealAmlAdminAlert,
        private readonly LotMailboxService $lotMailboxService,
        private readonly DealChatImapWakeMailer $dealChatImapWakeMailer,
    ) {}

    /**
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function handleSellerRequest(ExchangeLot $lot, string $sellerEscrowToken, string $buyerEmail): array
    {
        return DB::transaction(function () use ($lot, $sellerEscrowToken, $buyerEmail): array {
            $transaction = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('buyer_lot_email', $buyerEmail)
                ->where('tx_type', 'sale')
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                return $this->error(400, 'No pending sale transaction for this deal.', 'no_pending_tx');
            }

            if (! empty($transaction->seller_crypto_payout_tx_hash)) {
                return $this->error(409, 'Crypto payout already completed for this transaction.', 'payout_already_sent', [
                    'transaction_id' => $transaction->id,
                    'crypto_tx_id' => $transaction->seller_crypto_payout_tx_hash,
                    'payout_status' => 'completed',
                ]);
            }

            $existingJob = DealCryptoPayoutJob::query()
                ->where('transaction_id', $transaction->id)
                ->whereIn('status', [DealCryptoPayoutJob::STATUS_QUEUED, DealCryptoPayoutJob::STATUS_PROCESSING])
                ->first();
            if ($existingJob !== null) {
                return $this->queuedResponse($transaction, $existingJob);
            }

            $dest = trim((string) ($transaction->crypto_address ?? ''));
            if ($dest === '') {
                return $this->error(400, 'Buyer has not set a crypto destination address yet.', 'buyer_crypto_address_missing');
            }

            try {
                $cryptoType = DealEscrowCryptoType::fromEscrowTokenJson($sellerEscrowToken)
                    ?? DealEscrowCryptoType::sellerCoreType($lot);
            } catch (\InvalidArgumentException $e) {
                return $this->error(422, $e->getMessage(), 'invalid_lot_crypto');
            }

            $amount = SellerEscrowReservedAmount::resolve($lot, $cryptoType);
            if ($amount === null || $amount <= 0) {
                return $this->error(422, 'Cannot compute crypto payout amount for this deal and token.', 'invalid_payout_amount');
            }

            $addrError = DealPayoutDestinationAddressValidation::validate($dest, $cryptoType);
            if ($addrError !== null) {
                return $this->error(422, $addrError['message'], $addrError['error_code']);
            }

            [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerEscrowToken);
            $coreResult = $this->callCorePayout(
                $lot,
                $transaction,
                $sellerEscrowToken,
                $dest,
                $amount,
                $cryptoType,
                $coreUrl,
                $coreKey,
            );

            if ($coreResult['success']) {
                $finalize = $this->finalizeSuccessfulPayout(
                    $lot,
                    $transaction,
                    $sellerEscrowToken,
                    $buyerEmail,
                    $dest,
                    $amount,
                    $cryptoType,
                    $coreUrl,
                    $coreKey,
                    (string) $coreResult['crypto_tx_id'],
                    is_array($coreResult['core_body']) ? $coreResult['core_body'] : null,
                    (int) $coreResult['http_status'],
                );
                if (! $finalize['success']) {
                    return $this->error(500, (string) $finalize['message'], (string) ($finalize['error_code'] ?? 'deal_chat_persist_failed'));
                }

                return [
                    'http_status' => 200,
                    'body' => [
                        'success' => true,
                        'payout_status' => 'completed',
                        'message' => 'Crypto sent to buyer address. Waiting for buyer to confirm deal completion.',
                        'transaction_id' => $transaction->id,
                        'crypto_tx_id' => $coreResult['crypto_tx_id'],
                        'destination_address' => $dest,
                    ],
                ];
            }

            if (
                $cryptoType === 'xmr'
                && ($coreResult['unlocked_insufficient'] ?? false)
            ) {
                $job = $this->enqueueJob($lot, $transaction, $sellerEscrowToken, $cryptoType, (bool) $lot->aml, (string) ($coreResult['message'] ?? ''));

                return $this->queuedResponse($transaction, $job);
            }

            $this->persistPayoutAmlAndAlertOperatorIfNeeded(
                $lot,
                $transaction,
                'seller_crypto_payout_to_buyer',
                'seller',
                $sellerEscrowToken,
                $cryptoType,
                $coreUrl,
                $coreKey,
                (int) ($coreResult['http_status'] ?? 422),
                is_array($coreResult['core_body'] ?? null) ? $coreResult['core_body'] : ['raw' => (string) ($coreResult['message'] ?? '')],
            );

            return $this->error(
                (int) ($coreResult['http_status'] ?? 422) >= 500 ? 502 : 422,
                (string) ($coreResult['message'] ?? 'Core payout failed'),
                (string) ($coreResult['error_code'] ?? 'core_payout_failed'),
                [
                    'aml' => is_array($coreResult['core_body'] ?? null) ? ($coreResult['core_body']['aml'] ?? null) : null,
                ],
            );
        });
    }

    public function processQueuedJobs(int $limit = 20): int
    {
        $processed = 0;
        $jobs = DealCryptoPayoutJob::query()
            ->where('status', DealCryptoPayoutJob::STATUS_QUEUED)
            ->where(function ($q): void {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($jobs as $job) {
            $done = DB::transaction(function () use ($job): bool {
                $locked = DealCryptoPayoutJob::query()->whereKey($job->id)->lockForUpdate()->first();
                if ($locked === null || $locked->status !== DealCryptoPayoutJob::STATUS_QUEUED) {
                    return false;
                }
                $locked->status = DealCryptoPayoutJob::STATUS_PROCESSING;
                $locked->save();

                $lot = ExchangeLot::query()->find($locked->exchange_lot_id);
                $transaction = Transaction::query()->lockForUpdate()->find($locked->transaction_id);
                if ($lot === null || $transaction === null) {
                    $locked->status = DealCryptoPayoutJob::STATUS_FAILED;
                    $locked->last_error = 'lot_or_transaction_missing';
                    $locked->save();

                    return true;
                }

                if (! empty($transaction->seller_crypto_payout_tx_hash)) {
                    $locked->status = DealCryptoPayoutJob::STATUS_COMPLETED;
                    $locked->crypto_tx_id = (string) $transaction->seller_crypto_payout_tx_hash;
                    $locked->save();

                    return true;
                }

                $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
                $dest = trim((string) ($transaction->crypto_address ?? ''));
                $sellerEscrowToken = (string) $locked->seller_escrow_token_public;
                $cryptoType = (string) $locked->crypto_type;
                $amount = SellerEscrowReservedAmount::resolve($lot, $cryptoType);
                if ($buyerEmail === '' || $dest === '' || $amount === null || $amount <= 0) {
                    $locked->status = DealCryptoPayoutJob::STATUS_FAILED;
                    $locked->last_error = 'invalid_deal_state';
                    $locked->save();

                    return true;
                }

                [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerEscrowToken);
                $coreResult = $this->callCorePayout(
                    $lot,
                    $transaction,
                    $sellerEscrowToken,
                    $dest,
                    $amount,
                    $cryptoType,
                    $coreUrl,
                    $coreKey,
                );

                if ($coreResult['success']) {
                    $finalize = $this->finalizeSuccessfulPayout(
                        $lot,
                        $transaction,
                        $sellerEscrowToken,
                        $buyerEmail,
                        $dest,
                        $amount,
                        $cryptoType,
                        $coreUrl,
                        $coreKey,
                        (string) $coreResult['crypto_tx_id'],
                        is_array($coreResult['core_body']) ? $coreResult['core_body'] : null,
                        (int) $coreResult['http_status'],
                    );
                    $locked->status = $finalize['success']
                        ? DealCryptoPayoutJob::STATUS_COMPLETED
                        : DealCryptoPayoutJob::STATUS_FAILED;
                    $locked->crypto_tx_id = (string) $coreResult['crypto_tx_id'];
                    $locked->last_error = $finalize['success'] ? null : (string) ($finalize['message'] ?? 'finalize_failed');
                    $locked->save();

                    return true;
                }

                $locked->attempts = (int) $locked->attempts + 1;
                $locked->last_error = substr((string) ($coreResult['message'] ?? 'core_payout_failed'), 0, 512);

                if (($coreResult['unlocked_insufficient'] ?? false) && $locked->attempts < $locked->max_attempts) {
                    $locked->status = DealCryptoPayoutJob::STATUS_QUEUED;
                    $locked->next_attempt_at = now()->addSeconds((int) config('services.deal_crypto_payout.retry_interval_seconds', 300));
                    $locked->save();

                    return true;
                }

                $locked->status = DealCryptoPayoutJob::STATUS_FAILED;
                $locked->save();

                return true;
            });

            if ($done) {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function payoutStatusForLot(ExchangeLot $lot, string $buyerEmail): array
    {
        $transaction = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('buyer_lot_email', $buyerEmail)
            ->where('tx_type', 'sale')
            ->orderByDesc('id')
            ->first();

        if ($transaction === null) {
            return $this->error(404, 'No sale transaction for this deal.', 'no_sale_tx');
        }

        if (! empty($transaction->seller_crypto_payout_tx_hash)) {
            return [
                'http_status' => 200,
                'body' => [
                    'success' => true,
                    'payout_status' => 'completed',
                    'transaction_id' => $transaction->id,
                    'crypto_tx_id' => $transaction->seller_crypto_payout_tx_hash,
                ],
            ];
        }

        $job = DealCryptoPayoutJob::query()->where('transaction_id', $transaction->id)->first();
        if ($job === null) {
            return [
                'http_status' => 200,
                'body' => [
                    'success' => true,
                    'payout_status' => 'none',
                    'transaction_id' => $transaction->id,
                ],
            ];
        }

        return [
            'http_status' => 200,
            'body' => [
                'success' => true,
                'payout_status' => $job->status,
                'transaction_id' => $transaction->id,
                'job_id' => $job->id,
                'attempts' => $job->attempts,
                'last_error' => $job->last_error,
                'next_attempt_at' => $job->next_attempt_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{success: bool, http_status?: int, crypto_tx_id?: string, message?: string, error_code?: string, unlocked_insufficient?: bool, core_body?: array<string, mixed>|null}
     */
    private function callCorePayout(
        ExchangeLot $lot,
        Transaction $transaction,
        string $sellerEscrowToken,
        string $dest,
        float $amount,
        string $cryptoType,
        string $coreUrl,
        string $coreKey,
    ): array {
        $timeout = (int) config('services.core.payout_timeout', 120);
        $payload = [
            'exchange_lot_id' => (string) $lot->id,
            'laravel_transaction_id' => $transaction->id,
            'escrow_token' => $sellerEscrowToken,
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
        $coreEndpoint = rtrim($coreUrl, '/').'/api/deal-payout-to-buyer-address';

        try {
            $resp = $req->post($coreEndpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('[seller-crypto-payout] core exception', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'http_status' => 502,
                'message' => $e->getMessage(),
                'error_code' => 'core_payout_failed',
            ];
        }

        $body = $resp->json();
        if ($resp->successful()) {
            $cryptoTxId = '';
            if (is_array($body) && isset($body['data']['transaction_id'])) {
                $cryptoTxId = trim((string) $body['data']['transaction_id']);
            }
            if ($cryptoTxId === '') {
                $cryptoTxId = 'unknown';
            }

            return [
                'success' => true,
                'http_status' => $resp->status(),
                'crypto_tx_id' => $cryptoTxId,
                'core_body' => is_array($body) ? $body : null,
            ];
        }

        $message = is_array($body) ? (string) ($body['message'] ?? 'Core payout failed') : $resp->body();
        $errorCode = is_array($body) ? (string) ($body['error_code'] ?? 'core_payout_failed') : 'core_payout_failed';
        if ($errorCode === 'CRYPTO_ERROR' && MoneroUnlockedBalanceError::matches($message)) {
            $errorCode = 'seller_payout_unlocked_balance_insufficient';
        }

        return [
            'success' => false,
            'http_status' => $resp->status(),
            'message' => $message,
            'error_code' => $errorCode,
            'unlocked_insufficient' => $errorCode === 'seller_payout_unlocked_balance_insufficient',
            'core_body' => is_array($body) ? $body : null,
        ];
    }

    private function enqueueJob(
        ExchangeLot $lot,
        Transaction $transaction,
        string $sellerEscrowToken,
        string $cryptoType,
        bool $amlRequired,
        string $lastError,
    ): DealCryptoPayoutJob {
        return DealCryptoPayoutJob::query()->updateOrCreate(
            ['transaction_id' => $transaction->id],
            [
                'exchange_lot_id' => $lot->id,
                'seller_escrow_token_public' => $sellerEscrowToken,
                'crypto_type' => $cryptoType,
                'status' => DealCryptoPayoutJob::STATUS_QUEUED,
                'attempts' => 0,
                'max_attempts' => (int) config('services.deal_crypto_payout.max_attempts', 288),
                'next_attempt_at' => now()->addSeconds((int) config('services.deal_crypto_payout.retry_interval_seconds', 300)),
                'last_error' => substr($lastError, 0, 512),
                'aml_required' => $amlRequired,
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $coreBody
     * @return array{success: bool, message?: string, error_code?: string}
     */
    private function finalizeSuccessfulPayout(
        ExchangeLot $lot,
        Transaction $transaction,
        string $sellerEscrowToken,
        string $buyerEmail,
        string $dest,
        float $amount,
        string $cryptoType,
        string $coreUrl,
        string $coreKey,
        string $cryptoTxId,
        ?array $coreBody,
        int $httpStatus,
    ): array {
        $transaction->seller_crypto_payout_tx_hash = $cryptoTxId;
        $transaction->status = 'completed';
        $transaction->save();

        $this->persistPayoutAmlAndAlertOperatorIfNeeded(
            $lot,
            $transaction,
            'seller_crypto_payout_to_buyer',
            'seller',
            $sellerEscrowToken,
            $cryptoType,
            $coreUrl,
            $coreKey,
            $httpStatus,
            $coreBody,
        );

        try {
            $reqDel = Http::timeout((int) config('services.core.timeout', 10));
            if ($coreKey !== '') {
                $reqDel = $reqDel->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $reqDel->post(rtrim($coreUrl, '/').'/api/complete-reservation', [
                'exchange_lot_id' => (string) $lot->id,
                'escrow_token' => $sellerEscrowToken,
                'crypto_type' => $cryptoType,
                'aml_required' => (bool) $lot->aml,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[seller-crypto-payout] complete-reservation failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
        }

        $coreTxCheck = EscrowTokenOnlyCryptoDealRules::buildEscrowPayoutCoreTxCheck(
            $cryptoType,
            $cryptoTxId,
            $amount,
            $dest,
            'seller_second_leg',
        );
        $tagJson = json_encode([
            't' => 'deal_completed_seller',
            'lot_id' => (int) $lot->id,
            'crypto_payout_tx' => $cryptoTxId,
            'laravel_transaction_id' => $transaction->id,
            'via_escrow_server' => true,
            'core_tx_check' => $coreTxCheck,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $this->sendDealEmail(
                to: $buyerEmail,
                subject: 'Deal completed',
                body: 'Seller sent crypto to the buyer address on file. Deal completed.',
                tagJson: $tagJson,
            );
        } catch (\Throwable $e) {
            Log::warning('[seller-crypto-payout] mailbox event send failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
        }

        $sellerEmailForChat = $this->lotMailboxService->getMailboxEmailForAddress(
            $lot->wallet_address ?? '',
            $this->sellerMailboxCryptoCode($lot),
        ) ?? $lot->seller_lot_email;

        $chatPersisted = $this->insertDealChatMessage(
            $lot,
            'seller',
            $sellerEmailForChat ?? config('mail.from.address', 'noreply@xdexboard.com'),
            $buyerEmail,
            'Seller sent crypto to the buyer address on file. Deal completed.',
            $tagJson,
        );

        $this->closeOpenDisputesForLot($lot, 'seller', 'Deal completed by participants.');
        $lot->refresh();
        $this->dealChatImapWakeMailer->pokeMailboxesAfterServerChatWrite($lot);

        if (! $chatPersisted || ! $this->exchangeLotHasCompletedDealTagInChat($lot)) {
            return [
                'success' => false,
                'message' => 'Seller payout was sent but completion could not be saved to chat history.',
                'error_code' => 'deal_chat_persist_failed',
            ];
        }

        DealCryptoPayoutJob::query()
            ->where('transaction_id', $transaction->id)
            ->update([
                'status' => DealCryptoPayoutJob::STATUS_COMPLETED,
                'crypto_tx_id' => $cryptoTxId,
            ]);

        return ['success' => true];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{http_status: int, body: array<string, mixed>}
     */
    private function queuedResponse(Transaction $transaction, DealCryptoPayoutJob $job): array
    {
        return [
            'http_status' => 202,
            'body' => [
                'success' => true,
                'payout_status' => 'queued',
                'message' => 'Payout queued. Funds will be sent automatically when the Monero balance unlocks.',
                'transaction_id' => $transaction->id,
                'job_id' => $job->id,
                'next_attempt_at' => $job->next_attempt_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{http_status: int, body: array<string, mixed>}
     */
    private function error(int $httpStatus, string $message, string $errorCode, array $extra = []): array
    {
        return [
            'http_status' => $httpStatus,
            'body' => array_merge([
                'success' => false,
                'message' => $message,
                'error_code' => $errorCode,
            ], $extra),
        ];
    }

    private function exchangeLotHasCompletedDealTagInChat(ExchangeLot $lot): bool
    {
        $lotId = (string) $lot->id;
        $terminalTags = [
            'deal_completed_buyer',
            'deal_completed_seller',
            'deal_completed_seller_timeout',
            'buyer_exited',
            'seller_exited',
        ];
        $tags = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereNotNull('deal_tag')
            ->pluck('deal_tag');
        foreach ($tags as $raw) {
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $d = json_decode($raw, true);
            if (! is_array($d)) {
                continue;
            }
            if (! in_array((string) ($d['t'] ?? ''), $terminalTags, true)) {
                continue;
            }
            if ((string) ($d['lot_id'] ?? '') !== $lotId) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function closeOpenDisputesForLot(ExchangeLot $lot, string $resolvedBy, string $resolution): void
    {
        $active = DealDispute::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereIn('status', ['opened', 'in_review'])
            ->get();
        foreach ($active as $dispute) {
            $dispute->status = 'closed';
            $dispute->resolved_by = $resolvedBy;
            $dispute->resolution = $resolution;
            $dispute->resolved_at = now();
            $dispute->closed_at = now();
            $dispute->last_activity_at = now();
            $dispute->save();
        }
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
    ): bool {
        DealChatMessage::create([
            'exchange_lot_id' => $lot->id,
            'sender_side' => $senderSide,
            'from_email' => $fromEmail,
            'to_email' => $toEmail,
            'body' => $body,
            'deal_tag' => $dealTag,
        ]);

        return true;
    }
}

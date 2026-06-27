<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Mail\HighDeliveryPriority;
use App\Models\DealChatMessage;
use App\Models\ExchangeLot;
use App\Models\Transaction;
use App\Services\EscrowTokenValidationService;
use App\Services\LotMailboxService;
use App\Services\SandboxVirtualBankService;
use App\Support\BuyerPaymentTimeoutMinutes;
use App\Support\DealBuyerFirstLeg;
use App\Support\DealBuyerSecondLeg;
use App\Support\DealChatTagSequenceValidator;
use App\Support\DealEscrowCryptoType;
use App\Support\DealEscrowPublicPart;
use App\Support\SandboxTestReceiptPdf;
use App\Support\SellerEscrowReservedAmount;
use App\Support\TrainingDealEscrowTokenFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Server-side seller steps for sandbox training lots (is_training).
 * Uses real deal_tag progression and Core APIs — not client-side tag forgery.
 */
final class TrainingDealSellerActions
{
    public function __construct(
        private readonly LotMailboxService $lotMailboxService,
        private readonly EscrowTokenValidationService $escrowTokenValidation,
        private readonly DealWebPushNotifier $dealWebPushNotifier,
        private readonly TrainingDealEscrowCoreUpload $escrowCoreUpload,
    ) {}

    public function sendFirstLegPaymentDetails(ExchangeLot $lot): bool
    {
        $lot->refresh();
        if (DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot) === true) {
            return $this->sendBankPaymentDetails($lot);
        }
        if (DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot) === true) {
            return $this->sendCryptoPaymentDetails($lot);
        }

        Log::warning('[training-deal] payment_details: unknown first leg for lot', ['lot_id' => $lot->id]);

        return false;
    }

    public function sendBankPaymentDetails(ExchangeLot $lot): bool
    {
        $lot->refresh();
        if (! $this->canAct($lot)) {
            return false;
        }
        $state = DealChatTagSequenceValidator::computeStateForLot($lot);
        if ($state !== DealChatTagSequenceValidator::STATE_DEAL_STARTED) {
            return false;
        }

        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return false;
        }

        $sellerToken = $this->sellerEscrowToken();
        if ($sellerToken === null) {
            return false;
        }

        try {
            $cryptoType = DealEscrowCryptoType::sellerCoreType($lot);
        } catch (\InvalidArgumentException $e) {
            Log::warning('[training-deal] bank payment_details: crypto type', ['error' => $e->getMessage()]);

            return false;
        }

        $amount = ($lot->buyer_selected_amount ?? 0) > 0
            ? (float) $lot->buyer_selected_amount
            : (float) $lot->sell_amount;

        if (! $this->createCoreSellerReservation($lot, $sellerToken, $cryptoType)) {
            return false;
        }

        $bankData = (string) config('training_deal.virtual_bank_payment_details', 'Sandbox virtual bank (training). Check your deal mailbox for the practice receipt.');
        $txHash = substr('pd-tr-'.$lot->id.'-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)), 0, 64);

        $transaction = Transaction::create([
            'tx_hash' => $txHash,
            'tx_type' => 'sale',
            'from_address' => 'buyer',
            'to_address' => 'seller',
            'amount' => $amount,
            'exchange_lot_id' => $lot->id,
            'buyer_lot_email' => $buyerEmail,
            'bank_id' => SandboxVirtualBankService::virtualBankId(),
            'bank_details' => $bankData,
            'payment_details_set_by' => 'seller',
            'transaction_timeout_minutes' => BuyerPaymentTimeoutMinutes::effectiveForLot(
                isset($lot->buyer_payment_timeout_minutes) ? (int) $lot->buyer_payment_timeout_minutes : null
            ),
            'status' => 'pending',
            'is_test' => true,
            'test_group' => $lot->test_group,
        ]);

        $this->linkReservationToTransaction($lot, $transaction, $sellerToken, $cryptoType);

        $tag = [
            't' => 'payment_details',
            'lot_id' => (int) $lot->id,
            'tx_id' => (int) $transaction->id,
            'bank_id' => SandboxVirtualBankService::virtualBankId(),
            'bank_data' => $bankData,
        ];
        $tagJson = json_encode($tag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = 'Training seller: pay via sandbox virtual bank for the first leg.';

        $sent = $this->publishSellerChatMessage($lot, $buyerEmail, $body, $tagJson);
        if ($sent && SandboxVirtualBankService::lotUsesVirtualSandboxBank($lot)) {
            $this->sendSandboxReceiptToBuyer($lot, $transaction, $amount);
        }

        return $sent;
    }

    public function sendCryptoPaymentDetails(ExchangeLot $lot): bool
    {
        $lot->refresh();
        if (! $this->canAct($lot)) {
            return false;
        }
        $state = DealChatTagSequenceValidator::computeStateForLot($lot);
        if ($state !== DealChatTagSequenceValidator::STATE_DEAL_STARTED) {
            return false;
        }

        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return false;
        }

        $sellerToken = $this->sellerEscrowToken();
        $publicJson = $this->sellerEscrowPublicJson($lot);
        if ($sellerToken === null || $publicJson === null) {
            Log::warning('[training-deal] payment_details: missing seller token config');

            return false;
        }

        $cryptoAddress = DealEscrowPublicPart::blockchainReceiveAddressFromJson($publicJson);
        if ($cryptoAddress === null) {
            Log::warning('[training-deal] payment_details: no blockchain address in seller public JSON');

            return false;
        }

        try {
            $cryptoType = DealEscrowCryptoType::buyerCoreType($lot);
        } catch (\InvalidArgumentException $e) {
            Log::warning('[training-deal] payment_details: crypto type', ['error' => $e->getMessage()]);

            return false;
        }

        $lot->load(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);

        $amount = ($lot->buyer_selected_amount ?? 0) > 0
            ? (float) $lot->buyer_selected_amount
            : (float) $lot->sell_amount;

        if (! $this->createCoreSellerReservation($lot, $sellerToken, $cryptoType)) {
            return false;
        }

        $txHash = substr('pd-tr-'.$lot->id.'-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)), 0, 64);

        $transaction = Transaction::create([
            'tx_hash' => $txHash,
            'tx_type' => 'sale',
            'from_address' => 'buyer',
            'to_address' => 'seller',
            'amount' => $amount,
            'exchange_lot_id' => $lot->id,
            'buyer_lot_email' => $buyerEmail,
            'crypto_address' => $cryptoAddress,
            'payment_details_set_by' => 'seller',
            'transaction_timeout_minutes' => BuyerPaymentTimeoutMinutes::effectiveForLot(
                isset($lot->buyer_payment_timeout_minutes) ? (int) $lot->buyer_payment_timeout_minutes : null
            ),
            'status' => 'pending',
            'is_test' => true,
            'test_group' => $lot->test_group,
        ]);

        $this->linkReservationToTransaction($lot, $transaction, $sellerToken, $cryptoType);

        $tag = [
            't' => 'payment_details',
            'lot_id' => (int) $lot->id,
            'tx_id' => (int) $transaction->id,
            'crypto' => $cryptoType,
            'address' => $cryptoAddress,
        ];
        $tagJson = json_encode($tag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = 'Training seller: send '.$cryptoType.' to this address for the first leg.';

        return $this->publishSellerChatMessage($lot, $buyerEmail, $body, $tagJson);
    }

    public function confirmSellerReceivedFunds(ExchangeLot $lot): bool
    {
        $lot->refresh();
        if (! $this->canAct($lot)) {
            return false;
        }
        $state = DealChatTagSequenceValidator::computeStateForLot($lot);
        if ($state !== DealChatTagSequenceValidator::STATE_WAIT_SELLER_CONFIRM) {
            return false;
        }

        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return false;
        }

        $transaction = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('buyer_lot_email', $buyerEmail)
            ->where('tx_type', 'sale')
            ->orderByDesc('id')
            ->first();

        $tag = [
            't' => 'seller_received_funds',
            'lot_id' => (int) $lot->id,
        ];
        if ($transaction !== null) {
            $tag['tx_id'] = (int) $transaction->id;
        }
        $tagJson = json_encode($tag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = 'Training seller: first leg received. Please send your destination details.';

        return $this->publishSellerChatMessage($lot, $buyerEmail, $body, $tagJson);
    }

    /**
     * @param  array<string, mixed>  $buyerDestinationTag
     */
    public function executeSellerTransfer(ExchangeLot $lot, array $buyerDestinationTag): bool
    {
        $firstLegBank = DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot);
        $buyerReceivesCrypto = DealBuyerSecondLeg::buyerReceivesCrypto($lot, $firstLegBank);
        if ($buyerReceivesCrypto === true) {
            return $this->executeSellerCryptoTransfer($lot, $buyerDestinationTag);
        }
        if ($buyerReceivesCrypto === false) {
            return $this->executeSellerFiatTransfer($lot, $buyerDestinationTag);
        }

        Log::warning('[training-deal] seller_transfer: unknown second leg', ['lot_id' => $lot->id]);

        return false;
    }

    /**
     * @param  array<string, mixed>  $buyerDestinationTag
     */
    private function executeSellerCryptoTransfer(ExchangeLot $lot, array $buyerDestinationTag): bool
    {
        $lot->refresh();
        if (! $this->canAct($lot)) {
            return false;
        }
        $state = DealChatTagSequenceValidator::computeStateForLot($lot);
        if ($state !== DealChatTagSequenceValidator::STATE_WAIT_SELLER_TRANSFER) {
            return false;
        }

        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return false;
        }

        $sellerToken = $this->sellerEscrowToken();
        if ($sellerToken === null) {
            return false;
        }

        return (bool) DB::transaction(function () use ($lot, $buyerEmail, $sellerToken, $buyerDestinationTag): bool {
            $transaction = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('buyer_lot_email', $buyerEmail)
                ->where('tx_type', 'sale')
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                Log::warning('[training-deal] seller_transfer: no pending sale tx', ['lot_id' => $lot->id]);

                return false;
            }

            if (! empty($transaction->seller_crypto_payout_tx_hash)) {
                return $this->publishSellerTransferTag($lot, $buyerEmail, (string) $transaction->seller_crypto_payout_tx_hash, (int) $transaction->id);
            }

            $dest = trim((string) ($transaction->crypto_address ?? ($buyerDestinationTag['address'] ?? '')));
            if ($dest === '') {
                Log::warning('[training-deal] seller_transfer: missing buyer destination', ['lot_id' => $lot->id]);

                return false;
            }

            try {
                $cryptoType = DealEscrowCryptoType::sellerCoreType($lot);
            } catch (\InvalidArgumentException $e) {
                Log::warning('[training-deal] seller_transfer: crypto type', ['error' => $e->getMessage()]);

                return false;
            }

            $amount = SellerEscrowReservedAmount::resolve($lot, $cryptoType);
            if ($amount === null || $amount <= 0) {
                return false;
            }

            if (! $this->escrowCoreUpload->ensureSellerEncryptedPartUploaded($sellerToken)) {
                Log::warning('[training-deal] seller_transfer: encrypted upload failed', ['lot_id' => $lot->id]);

                return false;
            }

            [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerToken);
            $timeout = (int) config('services.core.payout_timeout', 120);
            $payload = [
                'exchange_lot_id' => (string) $lot->id,
                'laravel_transaction_id' => $transaction->id,
                'escrow_token' => $sellerToken,
                'destination_address' => $dest,
                'amount' => $amount,
                'crypto_type' => $cryptoType,
                'native_amount' => 0.0,
                'aml_required' => false,
            ];
            $network = DealEscrowCryptoType::coreNetworkFromCryptoType($cryptoType);
            if ($network !== null) {
                $payload['network'] = $network;
            }

            $req = Http::timeout($timeout)->asJson();
            if ($coreKey !== '') {
                $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $endpoint = rtrim($coreUrl, '/').'/api/deal-payout-to-buyer-address';
            Log::info('[training-deal] seller_transfer: core payout start', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'endpoint' => $endpoint,
            ]);
            $resp = $req->post($endpoint, $payload);
            if (! $resp->successful()) {
                Log::warning('[training-deal] seller_transfer: core payout failed', [
                    'lot_id' => $lot->id,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);

                return false;
            }

            $body = $resp->json();
            $cryptoTxId = '';
            if (is_array($body) && isset($body['data']['transaction_id'])) {
                $cryptoTxId = trim((string) $body['data']['transaction_id']);
            }
            if ($cryptoTxId === '') {
                $cryptoTxId = 'unknown';
            }

            $transaction->seller_crypto_payout_tx_hash = $cryptoTxId;
            $transaction->status = 'completed';
            $transaction->save();

            try {
                $reqDel = Http::timeout((int) config('services.core.timeout', 10));
                if ($coreKey !== '') {
                    $reqDel = $reqDel->withHeaders(['X-Core-Key' => $coreKey]);
                }
                $reqDel->post(rtrim($coreUrl, '/').'/api/complete-reservation', [
                    'exchange_lot_id' => (string) $lot->id,
                    'escrow_token' => $sellerToken,
                    'crypto_type' => $cryptoType,
                    'aml_required' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[training-deal] seller_transfer: complete-reservation failed', [
                    'lot_id' => $lot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $this->publishSellerTransferTag($lot, $buyerEmail, $cryptoTxId, (int) $transaction->id);
        });
    }

    /**
     * @param  array<string, mixed>  $buyerDestinationTag
     */
    private function executeSellerFiatTransfer(ExchangeLot $lot, array $buyerDestinationTag): bool
    {
        $lot->refresh();
        if (! $this->canAct($lot)) {
            return false;
        }
        $state = DealChatTagSequenceValidator::computeStateForLot($lot);
        if ($state !== DealChatTagSequenceValidator::STATE_WAIT_SELLER_TRANSFER) {
            return false;
        }

        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return false;
        }

        $sellerToken = $this->sellerEscrowToken();
        if ($sellerToken === null) {
            return false;
        }

        return (bool) DB::transaction(function () use ($lot, $buyerEmail, $sellerToken, $buyerDestinationTag): bool {
            $transaction = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('buyer_lot_email', $buyerEmail)
                ->where('tx_type', 'sale')
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                return false;
            }

            try {
                $cryptoType = DealEscrowCryptoType::sellerCoreType($lot);
            } catch (\InvalidArgumentException $e) {
                return false;
            }

            try {
                [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerToken);
                $req = Http::timeout((int) config('services.core.timeout', 10));
                if ($coreKey !== '') {
                    $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
                }
                $req->post(rtrim($coreUrl, '/').'/api/complete-reservation', [
                    'exchange_lot_id' => (string) $lot->id,
                    'escrow_token' => $sellerToken,
                    'crypto_type' => $cryptoType,
                    'aml_required' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[training-deal] fiat seller_transfer: complete-reservation failed', [
                    'lot_id' => $lot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $amount = ($lot->buyer_selected_amount ?? 0) > 0
                ? (float) $lot->buyer_selected_amount
                : (float) $lot->sell_amount;
            $transaction->status = 'completed';
            $transaction->save();

            if (SandboxVirtualBankService::lotUsesVirtualSandboxBank($lot)) {
                $this->sendSandboxReceiptToBuyer($lot, $transaction, $amount);
            }

            $tag = [
                't' => 'seller_transfer_tx',
                'lot_id' => (int) $lot->id,
                'tx_id' => (int) $transaction->id,
                'receipt_links' => ['sandbox-training-receipt'],
            ];
            $tagJson = json_encode($tag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $body = 'Training seller: sandbox bank transfer sent (second leg). Check your inbox for the practice receipt.';

            return $this->publishSellerChatMessage($lot, $buyerEmail, $body, $tagJson);
        });
    }

    private function sendSandboxReceiptToBuyer(ExchangeLot $lot, Transaction $transaction, float $amount): void
    {
        $to = trim((string) ($lot->buyer_notification_email ?? ''));
        if ($to === '') {
            return;
        }

        $fiatCode = 'USD';
        $lot->loadMissing(['toFiatCurrency', 'fromFiatCurrency']);
        if ($lot->toFiatCurrency) {
            $fiatCode = (string) $lot->toFiatCurrency->code;
        } elseif ($lot->fromFiatCurrency) {
            $fiatCode = (string) $lot->fromFiatCurrency->code;
        }

        $paymentAtUtc = ($transaction->created_at ?? now())->clone()->utc();
        $pdf = SandboxTestReceiptPdf::build([
            'Transfer receipt (sandbox training)',
            'Generated at: '.now('UTC')->format('d.m.Y H:i:s').' UTC',
            'Payment date/time: '.$paymentAtUtc->format('d.m.Y H:i:s').' UTC',
            'Beneficiary bank: '.(string) config('sandbox_virtual_bank.name_en'),
            'Payment amount: '.number_format($amount, 2, '.', ' ').' '.$fiatCode,
            'Reference: TRAINING-'.$lot->id.'-'.$transaction->id,
        ]);

        try {
            Mail::send([], [], function ($message) use ($to, $pdf, $lot, $transaction): void {
                $message->to($to);
                $message->subject('Sandbox training receipt — Lot #'.$lot->id);
                $message->from(config('mail.from.address', 'noreply@xdexboard.com'), config('mail.from.name', 'XBoard'));
                $message->text('Synthetic PDF receipt for sandbox training deal (Lot #'.$lot->id.', tx '.$transaction->id.').');
                $message->attachData($pdf, 'receipt-lot-'.$lot->id.'.pdf', ['mime' => 'application/pdf']);
                HighDeliveryPriority::apply($message);
            });
        } catch (\Throwable $e) {
            Log::warning('[training-deal] sandbox receipt mail failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function publishSellerTransferTag(ExchangeLot $lot, string $buyerEmail, string $cryptoTxId, int $transactionId): bool
    {
        $tag = [
            't' => 'seller_transfer_tx',
            'lot_id' => (int) $lot->id,
            'tx_number' => $cryptoTxId,
            'tx_id' => $transactionId,
        ];
        $tagJson = json_encode($tag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = 'Training seller: second-leg crypto sent. Tx: '.$cryptoTxId;

        return $this->publishSellerChatMessage($lot, $buyerEmail, $body, $tagJson);
    }

    private function publishSellerChatMessage(ExchangeLot $lot, string $buyerEmail, string $body, string $tagJson): bool
    {
        $publicJson = $this->sellerEscrowPublicJson($lot);
        $mailboxCode = $lot->toCryptoCurrency?->code ?? $lot->fromCryptoCurrency?->code ?? 'crypto';
        $sellerEmail = $this->lotMailboxService->getMailboxEmailForAddress($publicJson ?? '', $mailboxCode)
            ?? trim((string) ($lot->seller_lot_email ?? ''));

        if ($sellerEmail === '') {
            $sellerEmail = config('mail.from.address', 'noreply@xdexboard.com');
        }

        try {
            Mail::raw($body, function ($message) use ($buyerEmail, $tagJson) {
                $message->to($buyerEmail);
                $message->subject('Training deal — seller step');
                $message->from(config('mail.from.address', 'noreply@xdexboard.com'), config('mail.from.name', 'XBoard'));
                $message->getHeaders()->addTextHeader('X-XBoard-Deal-Tag', $tagJson);
                HighDeliveryPriority::apply($message);
            });
        } catch (\Throwable $e) {
            Log::warning('[training-deal] seller mail failed (chat row still inserted)', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            DealChatMessage::create([
                'exchange_lot_id' => $lot->id,
                'sender_side' => 'seller',
                'from_email' => $sellerEmail,
                'to_email' => $buyerEmail,
                'body' => $body,
                'deal_tag' => $tagJson,
            ]);
            $this->dealWebPushNotifier->notifyDealChatRecipient($lot, 'seller');
            Log::info('[training-deal] seller step published', [
                'lot_id' => $lot->id,
                'tag' => json_decode($tagJson, true),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[training-deal] chat insert failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function createCoreSellerReservation(ExchangeLot $lot, string $sellerToken, string $cryptoType): bool
    {
        $reservedAmount = SellerEscrowReservedAmount::resolve($lot, $cryptoType);
        if ($reservedAmount === null || $reservedAmount <= 0) {
            return false;
        }

        [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerToken);
        $timeout = (int) config('services.core.timeout', 10);
        $req = Http::timeout($timeout);
        if ($coreKey !== '') {
            $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
        }
        $payload = [
            'exchange_lot_id' => $lot->id,
            'reserved_amount' => $reservedAmount,
            'escrow_token' => $sellerToken,
            'crypto_type' => $cryptoType,
            'is_legal_entity' => false,
            'reservation_side' => 'seller',
        ];
        if (! empty($lot->buyer_escrow_token_hash)) {
            $payload['buyer_escrow_token_hash'] = $lot->buyer_escrow_token_hash;
        }

        $resp = $req->asJson()->post($coreUrl.'/api/reservations', $payload);
        if (! $resp->successful()) {
            Log::warning('[training-deal] seller reservation failed', [
                'lot_id' => $lot->id,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);

            return false;
        }

        return true;
    }

    private function linkReservationToTransaction(ExchangeLot $lot, Transaction $transaction, string $sellerToken, string $cryptoType): void
    {
        try {
            [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerToken);
            $req = Http::timeout((int) config('services.core.timeout', 10));
            if ($coreKey !== '') {
                $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $req->post($coreUrl.'/api/reservations/set-transaction-id', [
                'exchange_lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'escrow_token' => $sellerToken,
                'crypto_type' => $cryptoType,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[training-deal] set-transaction-id failed (non-blocking)', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function canAct(ExchangeLot $lot): bool
    {
        return (bool) ($lot->is_training ?? false);
    }

    private function sellerEscrowToken(): ?string
    {
        $token = trim((string) config('training_deal.seller_escrow_token', ''));

        return $token !== '' ? TrainingDealEscrowTokenFormat::normalizeForCore($token) : null;
    }

    private function sellerEscrowPublicJson(ExchangeLot $lot): ?string
    {
        $fromConfig = trim((string) config('training_deal.seller_escrow_public_json', ''));
        if ($fromConfig !== '' && str_starts_with($fromConfig, '{')) {
            return $fromConfig;
        }
        $token = trim((string) config('training_deal.seller_escrow_token', ''));
        if ($token !== '') {
            $derived = TrainingDealEscrowTokenFormat::publicPartJson($token);
            if ($derived !== null && str_starts_with($derived, '{')) {
                return $derived;
            }
        }
        $wa = trim((string) ($lot->wallet_address ?? ''));

        return str_starts_with($wa, '{') ? $wa : null;
    }
}

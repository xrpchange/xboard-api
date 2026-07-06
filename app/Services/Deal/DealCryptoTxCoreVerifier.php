<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Models\DealChatMessage;
use App\Models\ExchangeLot;
use App\Models\Transaction;
use App\Support\ApiDeploymentEnvironment;
use App\Support\CryptoToFiatDealSupport;
use App\Support\DealEscrowCryptoType;
use App\Support\DealEscrowPublicPart;
use App\Support\EscrowTokenOnlyCryptoDealRules;
use App\Support\SellerEscrowReservedAmount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * On-chain tx verification via Core (same contract as deal chat send path).
 */
final class DealCryptoTxCoreVerifier
{
    /**
     * @param  array<string, mixed>  $dealTagData  decoded deal_tag (must include `t`)
     * @return array{
     *   verified: bool,
     *   verification: array<string, mixed>,
     *   error: array{message:string,error_code:string}|null
     * }
     */
    /**
     * @param  array<string, mixed>  $dealTagData
     * @return array<string, mixed>
     */
    public function normalizeCryptoProofTag(array $dealTagData): array
    {
        $tagType = trim((string) ($dealTagData['t'] ?? ''));
        if ($tagType === 'deal_completed_seller') {
            return array_merge($dealTagData, [
                't' => 'seller_transfer_tx',
                'tx_number' => trim((string) ($dealTagData['crypto_payout_tx'] ?? '')),
            ]);
        }
        if ($tagType === 'operator_mail_reply' && trim((string) ($dealTagData['crypto_payout_tx'] ?? '')) !== '') {
            return array_merge($dealTagData, [
                't' => 'seller_transfer_tx',
                'tx_number' => trim((string) $dealTagData['crypto_payout_tx']),
            ]);
        }

        return $dealTagData;
    }

    public function verifyDealTag(ExchangeLot $lot, array $dealTagData): array
    {
        $dealTagData = $this->normalizeCryptoProofTag($dealTagData);
        $tagType = trim((string) ($dealTagData['t'] ?? ''));
        $manualReject = EscrowTokenOnlyCryptoDealRules::rejectManualCryptoProofIfRequired($lot, $dealTagData);
        if ($manualReject !== null) {
            return [
                'verified' => false,
                'verification' => [],
                'error' => [
                    'message' => $manualReject['message'],
                    'error_code' => $manualReject['error_code'],
                ],
            ];
        }
        if (! in_array($tagType, ['buyer_crypto_tx', 'seller_transfer_tx'], true)) {
            return [
                'verified' => false,
                'verification' => [],
                'error' => [
                    'message' => 'This chat tag is not an on-chain transfer proof.',
                    'error_code' => 'not_crypto_tx_tag',
                ],
            ];
        }

        if ($tagType === 'seller_transfer_tx') {
            $receiptLinks = $dealTagData['receipt_links'] ?? null;
            if (is_array($receiptLinks) && count($receiptLinks) > 0) {
                return [
                    'verified' => false,
                    'verification' => [],
                    'error' => [
                        'message' => 'This proof is a bank receipt (receipt_links), not an on-chain transaction id.',
                        'error_code' => 'bank_receipt_not_on_chain',
                    ],
                ];
            }
        }

        $txRef = trim((string) ($dealTagData['tx_ref'] ?? $dealTagData['transaction_id'] ?? $dealTagData['tx_number'] ?? ''));
        if ($txRef === '') {
            return [
                'verified' => false,
                'verification' => [],
                'error' => [
                    'message' => 'transaction id (tx_ref) is required for crypto transfer proof.',
                    'error_code' => 'crypto_tx_id_required',
                ],
            ];
        }

        try {
            $cryptoType = match ($tagType) {
                'buyer_crypto_tx' => CryptoToFiatDealSupport::isCryptoToFiatDeal($lot)
                    ? DealEscrowCryptoType::sellerCoreType($lot)
                    : DealEscrowCryptoType::buyerCoreType($lot),
                default => DealEscrowCryptoType::sellerCoreType($lot),
            };
        } catch (\InvalidArgumentException $e) {
            return [
                'verified' => false,
                'verification' => [],
                'error' => [
                    'message' => $e->getMessage(),
                    'error_code' => 'invalid_lot_crypto',
                ],
            ];
        }

        if (EscrowTokenOnlyCryptoDealRules::shouldVerifyViaEscrowRecords($lot, $dealTagData, $cryptoType, $tagType)) {
            $storedCheck = is_array($dealTagData['core_tx_check'] ?? null) ? $dealTagData['core_tx_check'] : null;
            if ($this->isStoredEscrowPayoutCheckValid($storedCheck, $txRef)) {
                return $this->buildStoredEscrowPayoutVerifyResult($lot, $storedCheck, $cryptoType, $txRef);
            }

            $expectedAddress = $this->expectedAddress($lot, $tagType);
            $expectedAmount = $this->expectedAmount($lot, $tagType);

            return $this->verifyEscrowTokenTransfer(
                $lot,
                $dealTagData,
                $tagType,
                $txRef,
                $cryptoType,
                $expectedAmount,
                $expectedAddress,
            );
        }

        $expectedAddress = $this->expectedAddress($lot, $tagType);
        $expectedAmount = $this->expectedAmount($lot, $tagType);

        $payload = [
            'transaction_id' => $txRef,
            'crypto_type' => $cryptoType,
            'min_confirmations' => 0,
            'strict_checks' => true,
        ];
        if ($expectedAddress !== null && $expectedAddress !== '' && self::supportsExpectedAddressCheck($cryptoType)) {
            $payload['expected_address'] = $expectedAddress;
        }
        if ($expectedAmount !== null && $expectedAmount > 0 && self::supportsExpectedAmountCheck($cryptoType)) {
            $payload['expected_amount'] = $expectedAmount;
        }

        $deploymentEnvironment = self::deploymentEnvironmentForLot($lot);
        $coreCfg = ApiDeploymentEnvironment::coreHttpConfig($deploymentEnvironment);
        $coreUrl = $coreCfg['url'];
        if ($coreUrl === '') {
            return [
                'verified' => false,
                'verification' => [],
                'error' => [
                    'message' => 'Core API URL is not configured for '.ApiDeploymentEnvironment::label($deploymentEnvironment).'.',
                    'error_code' => 'core_not_configured',
                ],
            ];
        }

        $endpoint = $coreUrl.'/api/escrow-tokens/verify-external-payment';
        $req = Http::timeout($coreCfg['timeout']);
        $coreKey = $coreCfg['key'];
        if ($coreKey !== '') {
            $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
        }

        try {
            $resp = $req->asJson()->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('[deal-crypto-tx-verify] transport exception', [
                'lot_id' => $lot->id,
                'tag_type' => $tagType,
                'tx_ref' => $txRef,
                'error' => $e->getMessage(),
            ]);

            return [
                'verified' => false,
                'verification' => [],
                'error' => [
                    'message' => 'Failed to verify crypto transaction id via Core.',
                    'error_code' => 'core_crypto_tx_verify_transport_failed',
                ],
            ];
        }

        $body = $resp->json();
        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];
        if (! $resp->successful()) {
            return [
                'verified' => false,
                'verification' => [],
                'error' => [
                    'message' => is_array($body) ? (string) ($body['message'] ?? 'Core tx verification failed.') : 'Core tx verification failed.',
                    'error_code' => is_array($body) ? (string) ($body['error_code'] ?? 'core_crypto_tx_verify_failed') : 'core_crypto_tx_verify_failed',
                ],
            ];
        }

        $verified = (bool) ($data['verified'] ?? false);
        $verification = [
            'source' => 'core_verify_external_payment',
            'checked_at_utc' => now()->utc()->toIso8601String(),
            'verified' => $verified,
            'status' => $data['status'] ?? null,
            'is_confirmed' => $data['is_confirmed'] ?? null,
            'confirmations' => $data['confirmations'] ?? null,
            'checks' => self::normalizeCoreChecks(is_array($data['checks'] ?? null) ? $data['checks'] : []),
            'observed' => is_array($data['observed'] ?? null) ? $data['observed'] : [],
            'expected' => is_array($data['expected'] ?? null) ? $data['expected'] : [],
            'crypto_type' => $cryptoType,
            'transaction_id' => $txRef,
            'deployment_environment' => $deploymentEnvironment,
            'blockchain_network' => self::blockchainNetworkId($cryptoType, $deploymentEnvironment),
        ];

        return [
            'verified' => $verified,
            'verification' => $verification,
            'error' => $verified ? null : [
                'message' => self::buildNotVerifiedMessage($data),
                'error_code' => 'crypto_tx_not_verified',
            ],
        ];
    }

    /**
     * @return array{
     *   verified: bool,
     *   verification: array<string, mixed>,
     *   error: array{message:string,error_code:string}|null,
     *   proof: array<string, mixed>|null
     * }
     */
    public function verifyChatMessage(ExchangeLot $lot, int $messageId): array
    {
        $row = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('id', $messageId)
            ->first();

        if ($row === null) {
            return [
                'verified' => false,
                'verification' => [],
                'error' => [
                    'message' => 'Chat message not found for this lot.',
                    'error_code' => 'message_not_found',
                ],
                'proof' => null,
            ];
        }

        $raw = trim((string) ($row->deal_tag ?? ''));
        $tag = is_string($raw) && str_starts_with($raw, '{') ? json_decode($raw, true) : null;
        if (! is_array($tag)) {
            return [
                'verified' => false,
                'verification' => [],
                'error' => [
                    'message' => 'Chat message has no structured deal_tag.',
                    'error_code' => 'no_deal_tag',
                ],
                'proof' => null,
            ];
        }

        $storedCheck = is_array($tag['core_tx_check'] ?? null) ? $tag['core_tx_check'] : null;
        $normalizedTag = $this->normalizeCryptoProofTag($tag);

        if ($this->shouldUseStoredTrainingTxCheck($lot, $storedCheck)) {
            return $this->resultFromStoredTrainingTxCheck($lot, $row, $tag, $normalizedTag, $storedCheck);
        }

        $result = $this->verifyDealTag($lot, $normalizedTag);

        return array_merge($result, [
            'proof' => [
                'message_id' => (int) $row->id,
                'sender_side' => (string) ($row->sender_side ?? ''),
                'tag_type' => (string) ($tag['t'] ?? ''),
                'tx_id' => $this->txRefFromTag($normalizedTag),
                'stored_core_tx_check' => $storedCheck,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $tag
     */
    public function txRefFromTag(array $tag): string
    {
        $tag = $this->normalizeCryptoProofTag($tag);
        $tagType = trim((string) ($tag['t'] ?? ''));

        return trim((string) match ($tagType) {
            'buyer_crypto_tx' => $tag['tx_ref'] ?? $tag['transaction_id'] ?? '',
            'seller_transfer_tx' => $tag['tx_number'] ?? $tag['transaction_id'] ?? $tag['tx_ref'] ?? '',
            default => '',
        });
    }

    /**
     * @param  array<string, mixed>  $result  output of verifyChatMessage
     * @return array<string, mixed>
     */
    public function apiPayloadFromResult(ExchangeLot $lot, array $result): array
    {
        $proof = is_array($result['proof'] ?? null) ? $result['proof'] : [];
        $verification = is_array($result['verification'] ?? null) ? $result['verification'] : [];
        $txId = trim((string) ($proof['tx_id'] ?? $verification['transaction_id'] ?? ''));
        $cryptoType = trim((string) ($verification['crypto_type'] ?? ''));
        if ($cryptoType === '') {
            try {
                $cryptoType = match ((string) ($proof['tag_type'] ?? '')) {
                    'buyer_crypto_tx' => CryptoToFiatDealSupport::isCryptoToFiatDeal($lot)
                        ? DealEscrowCryptoType::sellerCoreType($lot)
                        : DealEscrowCryptoType::buyerCoreType($lot),
                    default => DealEscrowCryptoType::sellerCoreType($lot),
                };
            } catch (\InvalidArgumentException) {
                $cryptoType = '';
            }
        }

        $environment = (bool) $lot->is_test
            ? ApiDeploymentEnvironment::SANDBOX
            : ApiDeploymentEnvironment::PRODUCTION;
        $explorerUrl = $txId !== '' && $cryptoType !== ''
            ? \App\Support\CryptoTxExplorerUrl::forTx($cryptoType, $txId, $environment)
            : null;

        return [
            'verified' => (bool) ($result['verified'] ?? false),
            'transaction_id' => $txId !== '' ? $txId : null,
            'crypto_type' => $cryptoType !== '' ? $cryptoType : null,
            'explorer_url' => $explorerUrl,
            'verification' => $verification,
            'stored_check' => is_array($proof['stored_core_tx_check'] ?? null) ? $proof['stored_core_tx_check'] : null,
            'message_id' => isset($proof['message_id']) ? (int) $proof['message_id'] : null,
            'tag_type' => $proof['tag_type'] ?? null,
            'error' => is_array($result['error'] ?? null) ? $result['error'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $storedCheck
     */
    private function shouldUseStoredTrainingTxCheck(ExchangeLot $lot, ?array $storedCheck): bool
    {
        if (! (bool) ($lot->is_training ?? false)) {
            return false;
        }
        if (! is_array($storedCheck)) {
            return false;
        }

        return ($storedCheck['training_auto'] ?? false) === true
            && ($storedCheck['verified'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $tag
     * @param  array<string, mixed>  $normalizedTag
     * @param  array<string, mixed>  $storedCheck
     * @return array{
     *   verified: bool,
     *   verification: array<string, mixed>,
     *   error: array{message:string,error_code:string}|null,
     *   proof: array<string, mixed>|null
     * }
     */
    private function resultFromStoredTrainingTxCheck(
        ExchangeLot $lot,
        DealChatMessage $row,
        array $tag,
        array $normalizedTag,
        array $storedCheck,
    ): array {
        $tagType = trim((string) ($tag['t'] ?? ''));
        $txRef = $this->txRefFromTag($normalizedTag);

        try {
            $cryptoType = $tagType === 'buyer_crypto_tx'
                ? DealEscrowCryptoType::buyerCoreType($lot)
                : DealEscrowCryptoType::sellerCoreType($lot);
        } catch (\InvalidArgumentException $e) {
            return [
                'verified' => false,
                'verification' => [],
                'error' => [
                    'message' => $e->getMessage(),
                    'error_code' => 'invalid_lot_crypto',
                ],
                'proof' => null,
            ];
        }

        $deploymentEnvironment = self::deploymentEnvironmentForLot($lot);
        $expectedAddress = $this->expectedAddress($lot, $tagType === 'buyer_crypto_tx' ? 'buyer_crypto_tx' : 'seller_transfer_tx');
        $expectedAmount = $this->expectedAmount($lot, $tagType === 'buyer_crypto_tx' ? 'buyer_crypto_tx' : 'seller_transfer_tx');

        $verification = [
            'source' => (string) ($storedCheck['source'] ?? 'training_deal_skip_onchain_verify'),
            'checked_at_utc' => (string) ($storedCheck['checked_at_utc'] ?? now()->utc()->toIso8601String()),
            'verified' => true,
            'training_auto' => true,
            'status' => 'training_accepted',
            'is_confirmed' => true,
            'confirmations' => 1,
            'checks' => [
                'confirmations_ok' => true,
                'address_matches' => null,
                'amount_matches' => null,
            ],
            'observed' => [],
            'expected' => array_filter([
                'address' => $expectedAddress,
                'amount' => $expectedAmount,
            ], static fn ($value) => $value !== null && $value !== ''),
            'crypto_type' => $cryptoType,
            'transaction_id' => $txRef,
            'deployment_environment' => $deploymentEnvironment,
            'blockchain_network' => self::blockchainNetworkId($cryptoType, $deploymentEnvironment),
        ];

        return [
            'verified' => true,
            'verification' => $verification,
            'error' => null,
            'proof' => [
                'message_id' => (int) $row->id,
                'sender_side' => (string) ($row->sender_side ?? ''),
                'tag_type' => $tagType,
                'tx_id' => $txRef,
                'stored_core_tx_check' => $storedCheck,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dealTagData
     * @return array{
     *   verified: bool,
     *   verification: array<string, mixed>,
     *   error: array{message:string,error_code:string}|null
     * }
     */
    private function verifyEscrowTokenTransfer(
        ExchangeLot $lot,
        array $dealTagData,
        string $tagType,
        string $txRef,
        string $cryptoType,
        ?float $expectedAmount,
        ?string $expectedAddress,
    ): array {
        $storedCheck = is_array($dealTagData['core_tx_check'] ?? null) ? $dealTagData['core_tx_check'] : null;
        if ($this->isStoredEscrowPayoutCheckValid($storedCheck, $txRef)) {
            return $this->buildStoredEscrowPayoutVerifyResult($lot, $storedCheck, $cryptoType, $txRef);
        }

        $deploymentEnvironment = self::deploymentEnvironmentForLot($lot);
        $transactionQuery = Transaction::query()->where('exchange_lot_id', $lot->id);
        $laravelTxId = isset($dealTagData['laravel_transaction_id']) && is_numeric($dealTagData['laravel_transaction_id'])
            ? (int) $dealTagData['laravel_transaction_id']
            : null;
        if ($laravelTxId !== null && $laravelTxId > 0) {
            $transactionQuery->where('id', $laravelTxId);
        }
        $transaction = $transactionQuery->orderByDesc('id')->first();

        $storedHash = match ($tagType) {
            'buyer_crypto_tx' => trim((string) ($transaction?->buyer_crypto_payout_tx_hash ?? '')),
            'seller_transfer_tx' => trim((string) ($transaction?->seller_crypto_payout_tx_hash ?? '')),
            default => '',
        };

        $verified = $storedHash !== ''
            && $storedHash !== 'unknown'
            && strcasecmp($storedHash, $txRef) === 0;

        $payoutRole = $tagType === 'buyer_crypto_tx' ? 'buyer_first_leg' : 'seller_second_leg';
        $verification = [
            'source' => 'escrow_token_payout',
            'checked_at_utc' => now()->utc()->toIso8601String(),
            'verified' => $verified,
            'status' => $verified ? 'escrow_payout_confirmed' : 'escrow_payout_not_found',
            'is_confirmed' => $verified,
            'confirmations' => null,
            'checks' => [
                'confirmations_ok' => null,
                'address_matches' => null,
                'amount_matches' => $verified ? true : null,
                'escrow_token_payout' => $verified,
            ],
            'observed' => array_filter([
                'transaction_id' => $verified ? $storedHash : null,
                'amount' => $verified ? $expectedAmount : null,
                'to_address' => $verified ? $expectedAddress : null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'expected' => array_filter([
                'amount' => $expectedAmount,
                'address' => $expectedAddress,
            ], static fn ($value) => $value !== null && $value !== ''),
            'crypto_type' => $cryptoType,
            'transaction_id' => $txRef,
            'deployment_environment' => $deploymentEnvironment,
            'blockchain_network' => self::blockchainNetworkId($cryptoType, $deploymentEnvironment),
            'payout_role' => $payoutRole,
            'laravel_transaction_id' => $transaction?->id,
        ];

        return [
            'verified' => $verified,
            'verification' => $verification,
            'error' => $verified ? null : [
                'message' => 'Transaction id does not match a server escrow-token payout recorded for this deal.',
                'error_code' => 'escrow_token_payout_not_verified',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $storedCheck
     */
    private function isStoredEscrowPayoutCheckValid(?array $storedCheck, string $txRef): bool
    {
        return is_array($storedCheck)
            && ($storedCheck['verified'] ?? false) === true
            && ($storedCheck['source'] ?? '') === 'escrow_token_payout'
            && strtolower(trim((string) ($storedCheck['transaction_id'] ?? ''))) === strtolower($txRef);
    }

    /**
     * @param  array<string, mixed>  $storedCheck
     * @return array{
     *   verified: bool,
     *   verification: array<string, mixed>,
     *   error: array{message:string,error_code:string}|null
     * }
     */
    private function buildStoredEscrowPayoutVerifyResult(
        ExchangeLot $lot,
        array $storedCheck,
        string $cryptoType,
        string $txRef,
    ): array {
        $deploymentEnvironment = self::deploymentEnvironmentForLot($lot);
        $verification = array_merge($storedCheck, [
            'checked_at_utc' => now()->utc()->toIso8601String(),
            'deployment_environment' => $deploymentEnvironment,
            'blockchain_network' => self::blockchainNetworkId($cryptoType, $deploymentEnvironment),
            'transaction_id' => $txRef,
        ]);

        return [
            'verified' => true,
            'verification' => $verification,
            'error' => null,
        ];
    }

    private function expectedAddress(ExchangeLot $lot, string $tagType): ?string
    {
        if ($tagType === 'buyer_crypto_tx') {
            $tx = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('payment_details_set_by', 'seller')
                ->whereNotNull('crypto_address')
                ->orderByDesc('id')
                ->first();
            $addr = trim((string) ($tx?->crypto_address ?? ''));
            if ($addr === '') {
                return null;
            }
            try {
                $cryptoType = CryptoToFiatDealSupport::isCryptoToFiatDeal($lot)
                    ? DealEscrowCryptoType::sellerCoreType($lot)
                    : DealEscrowCryptoType::buyerCoreType($lot);
            } catch (\InvalidArgumentException) {
                $cryptoType = null;
            }
            $normalized = DealEscrowPublicPart::normalizeReceiveAddressForVerification($addr, $cryptoType);

            return $normalized !== '' ? $normalized : null;
        }
        if ($tagType === 'seller_transfer_tx') {
            $rows = DealChatMessage::query()
                ->where('exchange_lot_id', $lot->id)
                ->orderByDesc('id')
                ->limit(60)
                ->pluck('deal_tag')
                ->all();
            foreach ($rows as $raw) {
                if (! is_string($raw) || trim($raw) === '') {
                    continue;
                }
                $obj = json_decode($raw, true);
                if (! is_array($obj) || (($obj['t'] ?? '') !== 'buyer_destination_details')) {
                    continue;
                }
                $addr = trim((string) ($obj['address'] ?? ''));
                if ($addr !== '') {
                    return $addr;
                }
            }
        }

        return null;
    }

    private function expectedAmount(ExchangeLot $lot, string $tagType): ?float
    {
        if ($tagType === 'buyer_crypto_tx') {
            if (CryptoToFiatDealSupport::isCryptoToFiatDeal($lot)) {
                return SellerEscrowReservedAmount::resolveForSellerEscrowLot($lot);
            }

            return SellerEscrowReservedAmount::resolveForBuyerEscrowLot($lot);
        }
        if ($tagType === 'seller_transfer_tx') {
            return SellerEscrowReservedAmount::resolveForSellerEscrowLot($lot);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return array<string, mixed>
     */
    public static function deploymentEnvironmentForLot(ExchangeLot $lot): string
    {
        return (bool) $lot->is_test
            ? ApiDeploymentEnvironment::SANDBOX
            : ApiDeploymentEnvironment::PRODUCTION;
    }

    public static function blockchainNetworkId(string $cryptoType, string $deploymentEnvironment): ?string
    {
        $type = strtolower(trim($cryptoType));
        $sandbox = $deploymentEnvironment === ApiDeploymentEnvironment::SANDBOX;
        if ($type === 'btc') {
            return $sandbox ? 'btc_testnet' : 'btc_mainnet';
        }
        if ($type === 'xmr') {
            return $sandbox ? 'xmr_stagenet' : 'xmr_mainnet';
        }
        if ($type === 'xrp') {
            return $sandbox ? 'xrp_testnet' : 'xrp_mainnet';
        }
        if (str_contains($type, 'tron')) {
            return $sandbox ? 'tron_nile' : 'tron_mainnet';
        }
        if (str_contains($type, 'polygon')) {
            return $sandbox ? 'polygon_amoy' : 'polygon_mainnet';
        }
        if (str_contains($type, 'stellar')) {
            return $sandbox ? 'stellar_testnet' : 'stellar_mainnet';
        }

        return null;
    }

    private static function supportsExpectedAddressCheck(string $cryptoType): bool
    {
        // Monero hides recipient addresses on-chain; daemon lookup confirms tx existence/confirmations only.
        return strtolower(trim($cryptoType)) !== 'xmr';
    }

    private static function supportsExpectedAmountCheck(string $cryptoType): bool
    {
        // RingCT output amounts are not observable from monerod get_transactions without wallet view keys.
        return strtolower(trim($cryptoType)) !== 'xmr';
    }

    private static function normalizeCoreChecks(array $checks): array
    {
        if (array_key_exists('confirmations_enough', $checks) && ! array_key_exists('confirmations_ok', $checks)) {
            $checks['confirmations_ok'] = $checks['confirmations_enough'];
        }

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $data  Core verify-external-payment `data` payload
     */
    private static function buildNotVerifiedMessage(array $data): string
    {
        if (($data['is_confirmed'] ?? null) === false) {
            return 'Transaction was found but is not confirmed on-chain.';
        }

        $checks = is_array($data['checks'] ?? null) ? $data['checks'] : [];
        $parts = [];
        if (($checks['address_matches'] ?? null) === false) {
            $parts[] = 'recipient address does not match the deal';
        }
        if (($checks['amount_matches'] ?? null) === false) {
            $parts[] = 'amount does not match the deal';
        }
        if (($checks['confirmations_enough'] ?? null) === false) {
            $parts[] = 'not enough confirmations';
        }
        if ($parts !== []) {
            return 'Transaction found on-chain, but '.implode('; ', $parts).'.';
        }

        return 'Transaction id was not verified on-chain by Core.';
    }
}

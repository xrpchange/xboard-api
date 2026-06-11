<?php

namespace App\Services;

use App\Models\ExchangeLot;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * Deal AML state lives only on {@see Transaction::$aml_check_json} (no separate log tables).
 * Core screens at payout time; this service merges screening + token charges into that JSON.
 */
class DealAmlPersistenceService
{
    /**
     * Sum AML reserve debits for an escrow token from active (pending) sale transactions.
     */
    public function outstandingChargeAmountForToken(string $tokenHash, bool $isTest = false): float
    {
        $tokenHash = strtolower(trim($tokenHash));
        if ($tokenHash === '') {
            return 0.0;
        }

        $rows = Transaction::query()
            ->where('is_test', $isTest)
            ->where('tx_type', 'sale')
            ->where('status', 'pending')
            ->whereNotNull('aml_check_json')
            ->get(['aml_check_json']);

        $total = 0.0;
        foreach ($rows as $row) {
            $state = $row->aml_check_json;
            if (is_array($state)) {
                $total += $this->sumTokenChargesInAmlState($state, $tokenHash);
            }
        }

        return max(0.0, $total);
    }

    /**
     * First seller AML screening at Core reservation (send-payment-details).
     *
     * @param  array<string, mixed>|null  $responseBody
     */
    public function handleReservationCoreResponse(
        ExchangeLot $lot,
        Transaction $transaction,
        string $escrowTokenJson,
        string $cryptoType,
        ?string $network,
        int $httpStatus,
        ?array $responseBody,
    ): void {
        $this->handlePayoutCoreResponse(
            $lot,
            $transaction,
            'seller_reservation',
            'seller',
            $escrowTokenJson,
            $cryptoType,
            $network,
            $httpStatus,
            $responseBody,
        );
    }

    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    public function handlePayoutCoreResponse(
        ExchangeLot $lot,
        Transaction $transaction,
        string $payoutContext,
        string $screeningSide,
        string $escrowTokenJson,
        string $cryptoType,
        ?string $network,
        int $httpStatus,
        ?array $responseBody,
    ): void {
        $amlBlock = $this->extractAmlBlock($responseBody);
        $errorCode = is_array($responseBody)
            ? (string) ($responseBody['error_code'] ?? '')
            : '';

        $state = is_array($transaction->aml_check_json) ? $transaction->aml_check_json : [];
        $state['last_context'] = $payoutContext;
        $state['last_http_status'] = $httpStatus;
        $state['last_aml'] = $amlBlock;
        $state['last_core_at'] = now()->utc()->toIso8601String();

        if ($amlBlock !== null) {
            $tokenHash = $this->tokenHashFromEscrowJson($escrowTokenJson);
            if ($tokenHash === '') {
                Log::warning('[transaction-aml] payout had aml block but escrow token_hash missing', [
                    'lot_id' => $lot->id,
                    'transaction_id' => $transaction->id,
                    'context' => $payoutContext,
                ]);
            } else {
                $passed = $this->inferPassed($httpStatus, $errorCode, $amlBlock);
                $screeningKey = $screeningSide === 'buyer' ? 'buyer_screening' : 'seller_screening';
                $state[$screeningKey] = $this->buildScreeningRecord(
                    $amlBlock,
                    $passed,
                    $screeningSide,
                    $tokenHash,
                    $cryptoType,
                    $network,
                    $payoutContext,
                );

                if (! $passed && $this->isUserFaultAmlFailure($errorCode, $amlBlock)) {
                    $charges = is_array($state['token_charges'] ?? null) ? $state['token_charges'] : [];
                    $state['token_charges'] = $this->appendFailedScreeningCharges(
                        $charges,
                        (int) $lot->id,
                        (int) $transaction->id,
                        $screeningSide,
                        $tokenHash,
                        $cryptoType,
                        $amlBlock,
                        $this->addressFromEscrowJson($escrowTokenJson),
                        $state,
                    );
                }
            }
        }

        $transaction->aml_check_json = $state;
        $transaction->save();
    }

    /**
     * User wallet did not pass AML rules — charge screening cost. Provider/server errors are excluded.
     *
     * @param  array<string, mixed>|null  $amlBlock
     */
    public function isUserFaultAmlFailure(string $errorCode, ?array $amlBlock): bool
    {
        if ($errorCode === 'AML_PROVIDER_UNAVAILABLE') {
            return false;
        }
        if ($errorCode === 'AML_CHECK_FAILED') {
            return true;
        }

        $reason = strtolower(trim((string) ($amlBlock['reason_code'] ?? '')));

        return in_array($reason, [
            'aml_blacklist_flag',
            'aml_risk_threshold_exceeded',
            'aml_stub_fail',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function sumTokenChargesInAmlState(array $state, string $tokenHash): float
    {
        $tokenHash = strtolower(trim($tokenHash));
        $charges = $state['token_charges'] ?? null;
        if (! is_array($charges)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($charges as $charge) {
            if (! is_array($charge)) {
                continue;
            }
            $hash = strtolower(trim((string) ($charge['charged_token_hash'] ?? '')));
            if ($hash === $tokenHash) {
                $total += max(0.0, (float) ($charge['amount'] ?? 0.0));
            }
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $amlBlock
     * @return array<string, mixed>
     */
    private function buildScreeningRecord(
        array $amlBlock,
        bool $passed,
        string $screeningSide,
        string $tokenHash,
        string $cryptoType,
        ?string $network,
        string $payoutContext,
    ): array {
        return [
            'side' => $screeningSide,
            'screened_token_hash' => strtolower(trim($tokenHash)),
            'crypto_type' => strtolower(trim($cryptoType)),
            'network' => $network !== null ? strtolower(trim($network)) : null,
            'passed' => $passed,
            'provider' => (string) ($amlBlock['provider'] ?? 'unknown'),
            'status' => (string) ($amlBlock['status'] ?? 'unknown'),
            'reason_code' => (string) ($amlBlock['reason_code'] ?? ''),
            'risk_score_percent' => isset($amlBlock['risk_score_percent']) && is_numeric($amlBlock['risk_score_percent'])
                ? (float) $amlBlock['risk_score_percent']
                : null,
            'cost_amount' => max(0.0, (float) ($amlBlock['cost_amount'] ?? 0.0)),
            'check_uid' => isset($amlBlock['check_uid']) ? (string) $amlBlock['check_uid'] : null,
            'payout_context' => $payoutContext,
            'at' => now()->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<string, mixed>  $amlBlock
     * @param  array<string, mixed>  $fullState
     * @return array<int, array<string, mixed>>
     */
    private function appendFailedScreeningCharges(
        array $existing,
        int $exchangeLotId,
        int $transactionId,
        string $screeningSide,
        string $tokenHash,
        string $cryptoType,
        array $amlBlock,
        ?string $address,
        array $fullState,
    ): array {
        $status = strtolower(trim((string) ($amlBlock['status'] ?? '')));
        if (! in_array($status, ['success', 'failed'], true)) {
            return $existing;
        }

        $byKey = [];
        foreach ($existing as $row) {
            if (is_array($row) && isset($row['charge_key'])) {
                $byKey[(string) $row['charge_key']] = $row;
            }
        }

        $currentCost = max(0.0, (float) ($amlBlock['cost_amount'] ?? 0.0));
        $checkUid = $amlBlock['check_uid'] ?? null;
        $cryptoTypeNorm = strtolower(trim($cryptoType));
        $tokenHashNorm = strtolower(trim($tokenHash));
        $addressForKey = $address ?? '';

        $add = function (array $charge) use (&$byKey): void {
            $key = (string) ($charge['charge_key'] ?? '');
            if ($key === '' || ($charge['amount'] ?? 0) <= 0) {
                return;
            }
            if (! isset($byKey[$key])) {
                $byKey[$key] = $charge;
            }
        };

        if ($screeningSide === 'buyer') {
            $add([
                'charge_key' => 'buyer-fail:'.$exchangeLotId.':'.$transactionId.':'.($checkUid ?? sha1($addressForKey.'|'.$transactionId)),
                'charged_token_hash' => $tokenHashNorm,
                'charged_side' => 'buyer',
                'amount' => $currentCost,
                'currency_code' => $cryptoTypeNorm,
                'charge_reason' => 'buyer_failed_own_check',
            ]);
        } elseif ($screeningSide === 'seller') {
            $add([
                'charge_key' => 'seller-fail-own:'.$exchangeLotId.':'.$transactionId.':'.($checkUid ?? sha1($addressForKey.'|'.$transactionId)),
                'charged_token_hash' => $tokenHashNorm,
                'charged_side' => 'seller',
                'amount' => $currentCost,
                'currency_code' => $cryptoTypeNorm,
                'charge_reason' => 'seller_failed_own_check',
            ]);

            $buyerScreening = $fullState['buyer_screening'] ?? null;
            if (is_array($buyerScreening) && ! empty($buyerScreening['passed'])) {
                $buyerCost = max(0.0, (float) ($buyerScreening['cost_amount'] ?? 0.0));
                if ($buyerCost > 0) {
                    $add([
                        'charge_key' => 'seller-fail-prior-buyer:'.$exchangeLotId.':'.$transactionId,
                        'charged_token_hash' => $tokenHashNorm,
                        'charged_side' => 'seller',
                        'amount' => $buyerCost,
                        'currency_code' => $cryptoTypeNorm,
                        'charge_reason' => 'seller_failed_prior_buyer_check',
                    ]);
                }
            }
        }

        return array_values($byKey);
    }

    /**
     * @param  array<string, mixed>|null  $responseBody
     * @return array<string, mixed>|null
     */
    private function extractAmlBlock(?array $responseBody): ?array
    {
        if (! is_array($responseBody)) {
            return null;
        }
        if (isset($responseBody['aml']) && is_array($responseBody['aml'])) {
            return $responseBody['aml'];
        }
        if (isset($responseBody['data']['aml']) && is_array($responseBody['data']['aml'])) {
            return $responseBody['data']['aml'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $amlBlock
     */
    private function inferPassed(int $httpStatus, string $errorCode, array $amlBlock): bool
    {
        if ($errorCode === 'AML_CHECK_FAILED' || $errorCode === 'AML_PROVIDER_UNAVAILABLE') {
            return false;
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return false;
        }
        $reason = strtolower(trim((string) ($amlBlock['reason_code'] ?? '')));
        if (in_array($reason, ['aml_stub_fail', 'aml_blacklist_flag', 'aml_risk_threshold_exceeded', 'aml_provider_failed', 'aml_provider_error'], true)) {
            return false;
        }

        return true;
    }

    private function tokenHashFromEscrowJson(string $escrowTokenJson): string
    {
        $data = json_decode($escrowTokenJson, true);
        if (! is_array($data)) {
            return '';
        }
        $hash = $data['token_hash'] ?? '';

        return is_string($hash) ? strtolower(trim($hash)) : '';
    }

    private function addressFromEscrowJson(string $escrowTokenJson): ?string
    {
        $data = json_decode($escrowTokenJson, true);
        if (! is_array($data)) {
            return null;
        }
        $walletAddress = $data['wallet_address'] ?? null;
        if (is_string($walletAddress)) {
            $walletAddress = trim($walletAddress);

            return $walletAddress !== '' ? $walletAddress : null;
        }
        if (is_array($walletAddress)) {
            foreach (['wallet_address', 'address', 'primary_address', 'primary'] as $key) {
                if (! empty($walletAddress[$key]) && is_string($walletAddress[$key])) {
                    $candidate = trim($walletAddress[$key]);
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }
}

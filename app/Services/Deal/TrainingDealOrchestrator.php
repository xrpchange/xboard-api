<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Models\ExchangeLot;
use App\Support\ApiDeploymentEnvironment;
use Illuminate\Support\Facades\Log;

/**
 * After buyer deal_tag on sandbox training lots, perform the next seller step server-side.
 */
final class TrainingDealOrchestrator
{
    public function __construct(
        private readonly TrainingDealSellerActions $sellerActions,
    ) {}

    public function isEnabledForLot(ExchangeLot $lot): bool
    {
        if (! (bool) ($lot->is_training ?? false)) {
            return false;
        }
        if (! filter_var(config('training_deal.enabled', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return ApiDeploymentEnvironment::current() === ApiDeploymentEnvironment::SANDBOX;
    }

    /**
     * @param  array<string, mixed>|null  $dealTagData
     */
    public function advanceAfterBuyerAction(ExchangeLot $lot, ?array $dealTagData): void
    {
        if (! $this->isEnabledForLot($lot)) {
            return;
        }
        if (! is_array($dealTagData)) {
            return;
        }

        $t = trim((string) ($dealTagData['t'] ?? ''));
        if ($t === '') {
            return;
        }

        Log::info('[training-deal] advance after buyer tag', [
            'lot_id' => $lot->id,
            'tag' => $t,
        ]);

        match ($t) {
            'start_deal' => $this->sellerActions->sendFirstLegPaymentDetails($lot),
            'receipt_confirmation' => $this->sellerActions->confirmSellerReceivedFunds($lot),
            'buyer_crypto_tx' => $this->sellerActions->confirmSellerReceivedFunds($lot),
            'buyer_destination_details' => $this->sellerActions->executeSellerTransfer($lot, $dealTagData),
            default => null,
        };
    }

    /** Best-effort Core seller reservation release after training deal completion. */
    public function releaseSellerCoreReservationAfterCompletion(ExchangeLot $lot): void
    {
        if (! (bool) ($lot->is_training ?? false)) {
            return;
        }
        $this->sellerActions->releaseSellerCoreReservation($lot);
    }

    /**
     * Practice deals: accept buyer/seller crypto tx tags without waiting for on-chain Core verify.
     *
     * @param  array<string, mixed>  $dealTagData
     * @return array<string, mixed>
     */
    public function bypassCoreTxVerificationForTag(ExchangeLot $lot, array $dealTagData): array
    {
        if (! $this->isEnabledForLot($lot)) {
            return $dealTagData;
        }

        $tagType = trim((string) ($dealTagData['t'] ?? ''));
        if (! in_array($tagType, ['buyer_crypto_tx', 'seller_transfer_tx'], true)) {
            return $dealTagData;
        }

        if ($tagType === 'seller_transfer_tx') {
            $receiptLinks = $dealTagData['receipt_links'] ?? null;
            if (is_array($receiptLinks) && count($receiptLinks) > 0) {
                return $dealTagData;
            }
        }

        $txRef = trim((string) ($dealTagData['tx_ref'] ?? $dealTagData['transaction_id'] ?? $dealTagData['tx_number'] ?? ''));
        if ($txRef === '') {
            return $dealTagData;
        }

        $dealTagData['core_tx_check'] = [
            'source' => 'training_deal_skip_onchain_verify',
            'checked_at_utc' => now('UTC')->toIso8601String(),
            'verified' => true,
            'training_auto' => true,
        ];

        return $dealTagData;
    }
}

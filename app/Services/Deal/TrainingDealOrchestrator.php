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
}

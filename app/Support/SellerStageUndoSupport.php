<?php

namespace App\Support;

use App\Models\DealChatMessage;
use App\Models\ExchangeLot;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Seller correction: undo the last seller-owned deal step via chat tag {@see seller_stage_undo}.
 *
 * @see xboard-client DealChatTagState (seller_stage_undo)
 */
final class SellerStageUndoSupport
{
    public const TAG = 'seller_stage_undo';

    public const STEP_PAYMENT_DETAILS = 'payment_details';

    public const STEP_SELLER_RECEIVED_FUNDS = 'seller_received_funds';

    public const STEP_SELLER_TRANSFER_TX = 'seller_transfer_tx';

    /**
     * @return array{message: string, error_code: string}|null
     */
    public static function validateSellerStageUndo(ExchangeLot $lot, array $dealTagData): ?array
    {
        $step = trim((string) ($dealTagData['step'] ?? ''));
        if ($step === '') {
            return [
                'message' => 'seller_stage_undo must include non-empty "step".',
                'error_code' => 'deal_tag_invalid',
            ];
        }
        if (! in_array($step, [
            self::STEP_PAYMENT_DETAILS,
            self::STEP_SELLER_RECEIVED_FUNDS,
            self::STEP_SELLER_TRANSFER_TX,
        ], true)) {
            return [
                'message' => 'Unknown seller_stage_undo step.',
                'error_code' => 'deal_tag_invalid',
            ];
        }

        $state = DealChatTagSequenceValidator::computeStateForLot($lot);

        return match ($step) {
            self::STEP_PAYMENT_DETAILS => self::validateUndoPaymentDetails($state),
            self::STEP_SELLER_RECEIVED_FUNDS => self::validateUndoSellerReceivedFunds($state),
            self::STEP_SELLER_TRANSFER_TX => self::validateUndoSellerTransferTx($lot, $state),
            default => [
                'message' => 'Unexpected seller_stage_undo step.',
                'error_code' => 'deal_tag_invalid',
            ],
        };
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    private static function validateUndoPaymentDetails(string $state): ?array
    {
        if ($state !== DealChatTagSequenceValidator::STATE_WAIT_BUYER_PAY) {
            return [
                'message' => 'Can undo payment details only while waiting for the buyer’s first payment (before buyer proof).',
                'error_code' => 'seller_stage_undo_not_allowed',
            ];
        }

        return null;
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    private static function validateUndoSellerReceivedFunds(string $state): ?array
    {
        if ($state !== DealChatTagSequenceValidator::STATE_WAIT_BUYER_DEST) {
            return [
                'message' => 'Can undo “funds received” only before the buyer posts destination details for the second leg.',
                'error_code' => 'seller_stage_undo_not_allowed',
            ];
        }

        return null;
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    private static function validateUndoSellerTransferTx(ExchangeLot $lot, string $state): ?array
    {
        if ($state !== DealChatTagSequenceValidator::STATE_WAIT_BUYER_COMPLETE) {
            return [
                'message' => 'Can undo seller payout proof only while waiting for buyer completion.',
                'error_code' => 'seller_stage_undo_not_allowed',
            ];
        }
        $lotIdStr = (string) $lot->id;
        $tags = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereNotNull('deal_tag')
            ->orderBy('id')
            ->pluck('deal_tag')
            ->all();
        foreach ($tags as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $obj = json_decode($raw, true);
            if (! is_array($obj) || (string) ($obj['lot_id'] ?? '') !== $lotIdStr) {
                continue;
            }
            if (trim((string) ($obj['t'] ?? '')) === 'deal_completed_buyer') {
                return [
                    'message' => 'Cannot undo seller payout: the buyer already confirmed deal completion.',
                    'error_code' => 'seller_stage_undo_not_allowed',
                ];
            }
        }
        if (! DealChatTagSequenceValidator::lotAwaitingBuyerCompletionConfirm($lot)) {
            $hasTransferProof = false;
            foreach ($tags as $raw) {
                if (! is_string($raw)) {
                    continue;
                }
                $obj = json_decode($raw, true);
                if (! is_array($obj) || (string) ($obj['lot_id'] ?? '') !== $lotIdStr) {
                    continue;
                }
                $t = trim((string) ($obj['t'] ?? ''));
                if ($t === 'seller_transfer_tx') {
                    $hasTransferProof = true;
                }
            }
            if (! $hasTransferProof) {
                return [
                    'message' => 'No seller transfer proof to undo at this point.',
                    'error_code' => 'seller_stage_undo_not_allowed',
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $obj
     */
    public static function applyTagObjectToState(string $state, array $obj): string
    {
        $step = trim((string) ($obj['step'] ?? ''));

        return match ($step) {
            self::STEP_PAYMENT_DETAILS => DealChatTagSequenceValidator::STATE_DEAL_STARTED,
            self::STEP_SELLER_RECEIVED_FUNDS => DealChatTagSequenceValidator::STATE_WAIT_SELLER_CONFIRM,
            self::STEP_SELLER_TRANSFER_TX => DealChatTagSequenceValidator::STATE_WAIT_SELLER_TRANSFER,
            default => $state,
        };
    }

    public static function applyDatabaseSideEffects(
        ExchangeLot $lot,
        string $step,
        ?string $sellerEscrowToken,
        callable $getCoreUrlAndKeyForToken,
    ): void {
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return;
        }

        $pendingSale = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('buyer_lot_email', $buyerEmail)
            ->where('tx_type', 'sale')
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();

        if ($step === self::STEP_PAYMENT_DETAILS) {
            if ($pendingSale !== null) {
                $pendingSale->delete();
            }
            if ($sellerEscrowToken !== null && $sellerEscrowToken !== '') {
                try {
                    [$coreUrl, $coreKey] = $getCoreUrlAndKeyForToken($sellerEscrowToken);
                    $timeout = (int) config('services.core.timeout', 10);
                    $req = Http::timeout($timeout);
                    if ($coreKey !== '') {
                        $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
                    }
                    $req->post(rtrim($coreUrl, '/').'/api/delete-reservation', [
                        'exchange_lot_id' => $lot->id,
                        'escrow_token' => $sellerEscrowToken,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('[exchange-lots] seller_stage_undo: Core delete-reservation exception', [
                        'lot_id' => $lot->id,
                        'step' => $step,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return;
        }

        if ($step === self::STEP_SELLER_TRANSFER_TX && $pendingSale !== null) {
            $pendingSale->seller_crypto_payout_tx_hash = null;
            $pendingSale->save();
        }
    }
}

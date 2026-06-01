<?php

namespace App\Support;

use App\Models\DealChatMessage;
use App\Models\ExchangeLot;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Seller step correction requires buyer consent via two chat tags:
 * 1. {@see TAG_REQUEST} — seller asks to revert a seller-owned step.
 * 2. {@see TAG_CONFIRM} — buyer approves; state and DB roll back.
 *
 * In principle, rollback could be inferred from “request tag then confirm tag” in order alone.
 * Field `confirm_code` is an extra pair id in X-XBoard-Deal-Tag so multiple open or historical
 * requests (same or different `step`) do not get matched to the wrong confirm message.
 * The client never asks the user to type it; it is copied from the parsed request tag into confirm.
 *
 * Legacy {@see TAG_LEGACY} is accepted only when replaying old chat history (not for new messages).
 *
 * @see xboard-client DealChatTagState
 */
final class SellerStageUndoSupport
{
    public const TAG_REQUEST = 'seller_stage_undo_request';

    public const TAG_CONFIRM = 'buyer_stage_undo_confirm';

    /** @deprecated New messages must use request + confirm flow. */
    public const TAG_LEGACY = 'seller_stage_undo';

    public const STEP_PAYMENT_DETAILS = 'payment_details';

    public const STEP_SELLER_RECEIVED_FUNDS = 'seller_received_funds';

    public const STEP_SELLER_TRANSFER_TX = 'seller_transfer_tx';

    public static function generateConfirmCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{step: string, confirm_code: string}|null
     */
    public static function findOpenUndoRequestForLot(ExchangeLot $lot): ?array
    {
        $tags = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereNotNull('deal_tag')
            ->orderBy('id')
            ->pluck('deal_tag')
            ->all();

        return self::findOpenUndoRequestFromTags($tags, (string) $lot->id);
    }

    /**
     * @param  list<string|null>  $orderedDealTagJson
     * @return array{step: string, confirm_code: string}|null
     */
    public static function findOpenUndoRequestFromTags(array $orderedDealTagJson, string $lotIdStr): ?array
    {
        $pending = null;
        foreach ($orderedDealTagJson as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $obj = json_decode($raw, true);
            if (! is_array($obj) || (string) ($obj['lot_id'] ?? '') !== $lotIdStr) {
                continue;
            }
            $t = trim((string) ($obj['t'] ?? ''));
            if ($t === self::TAG_REQUEST) {
                $step = trim((string) ($obj['step'] ?? ''));
                $code = trim((string) ($obj['confirm_code'] ?? ''));
                if ($step !== '' && $code !== '') {
                    $pending = ['step' => $step, 'confirm_code' => $code];
                }

                continue;
            }
            if ($t === self::TAG_CONFIRM) {
                $step = trim((string) ($obj['step'] ?? ''));
                $code = trim((string) ($obj['confirm_code'] ?? ''));
                if ($pending !== null
                    && $pending['step'] === $step
                    && $pending['confirm_code'] === $code) {
                    $pending = null;
                }

                continue;
            }
            if ($t === self::TAG_LEGACY) {
                $pending = null;
            }
        }

        return $pending;
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    public static function validateSellerStageUndoRequest(ExchangeLot $lot, array $dealTagData): ?array
    {
        if (self::findOpenUndoRequestForLot($lot) !== null) {
            return [
                'message' => 'An undo request is already waiting for buyer confirmation.',
                'error_code' => 'seller_stage_undo_request_pending',
            ];
        }
        if (BuyerStageUndoSupport::findOpenUndoRequestForLot($lot) !== null) {
            return [
                'message' => 'Cannot open a seller undo request while a buyer undo request is pending.',
                'error_code' => 'stage_undo_request_pending',
            ];
        }
        if (trim((string) ($dealTagData['confirm_code'] ?? '')) !== '') {
            return [
                'message' => 'confirm_code is assigned by the server on seller_stage_undo_request.',
                'error_code' => 'deal_tag_invalid',
            ];
        }

        return self::validateUndoStepAllowed($lot, $dealTagData);
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    public static function validateBuyerStageUndoConfirm(ExchangeLot $lot, array $dealTagData): ?array
    {
        $step = trim((string) ($dealTagData['step'] ?? ''));
        $code = trim((string) ($dealTagData['confirm_code'] ?? ''));
        if ($step === '' || $code === '') {
            return [
                'message' => 'buyer_stage_undo_confirm must include non-empty "step" and "confirm_code".',
                'error_code' => 'deal_tag_invalid',
            ];
        }
        $open = self::findOpenUndoRequestForLot($lot);
        if ($open === null) {
            return [
                'message' => 'No pending seller undo request for this deal.',
                'error_code' => 'seller_stage_undo_no_pending_request',
            ];
        }
        if ($open['step'] !== $step || $open['confirm_code'] !== $code) {
            return [
                'message' => 'confirm_code or step does not match the open undo request.',
                'error_code' => 'seller_stage_undo_confirm_mismatch',
            ];
        }

        return self::validateUndoStepAllowed($lot, $dealTagData);
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    public static function rejectLegacyDirectUndo(): array
    {
        return [
            'message' => 'Direct seller_stage_undo is not allowed. Use seller_stage_undo_request and buyer_stage_undo_confirm.',
            'error_code' => 'seller_stage_undo_requires_buyer_confirm',
        ];
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    private static function validateUndoStepAllowed(ExchangeLot $lot, array $dealTagData): ?array
    {
        $step = trim((string) ($dealTagData['step'] ?? ''));
        if ($step === '') {
            return [
                'message' => 'Undo tag must include non-empty "step".',
                'error_code' => 'deal_tag_invalid',
            ];
        }
        if (! in_array($step, [
            self::STEP_PAYMENT_DETAILS,
            self::STEP_SELLER_RECEIVED_FUNDS,
            self::STEP_SELLER_TRANSFER_TX,
        ], true)) {
            return [
                'message' => 'Unknown undo step.',
                'error_code' => 'deal_tag_invalid',
            ];
        }

        $state = DealChatTagSequenceValidator::computeStateForLot($lot);

        return match ($step) {
            self::STEP_PAYMENT_DETAILS => self::validateUndoPaymentDetails($state),
            self::STEP_SELLER_RECEIVED_FUNDS => self::validateUndoSellerReceivedFunds($state),
            self::STEP_SELLER_TRANSFER_TX => self::validateUndoSellerTransferTx($lot, $state),
            default => [
                'message' => 'Unexpected undo step.',
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
        $t = trim((string) ($obj['t'] ?? ''));
        if ($t === self::TAG_REQUEST) {
            return $state;
        }

        $step = trim((string) ($obj['step'] ?? ''));
        if ($t !== self::TAG_CONFIRM && $t !== self::TAG_LEGACY) {
            return $state;
        }

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

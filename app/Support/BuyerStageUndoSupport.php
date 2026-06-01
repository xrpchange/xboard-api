<?php

namespace App\Support;

use App\Models\DealChatMessage;
use App\Models\ExchangeLot;

/**
 * Buyer step correction requires seller consent via two chat tags:
 * 1. {@see TAG_REQUEST} — buyer asks to revert a buyer-owned step.
 * 2. {@see TAG_CONFIRM} — seller approves; state rolls back in the tag reducer.
 *
 * Field `confirm_code` pairs request and confirm when several undo requests exist in chat.
 *
 * @see xboard-client DealChatTagState
 */
final class BuyerStageUndoSupport
{
    public const TAG_REQUEST = 'buyer_stage_undo_request';

    public const TAG_CONFIRM = 'seller_stage_undo_confirm';

    public const TAG_REJECT = 'seller_stage_undo_reject';

    public const STEP_BUYER_PAYMENT_PROOF = 'buyer_payment_proof';

    public const STEP_BUYER_DESTINATION_DETAILS = 'buyer_destination_details';

    public const STEP_DEAL_COMPLETED_BUYER = 'deal_completed_buyer';

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
            }
        }

        return $pending;
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    public static function validateBuyerStageUndoRequest(ExchangeLot $lot, array $dealTagData): ?array
    {
        if (self::findOpenUndoRequestForLot($lot) !== null) {
            return [
                'message' => 'An undo request is already waiting for seller confirmation.',
                'error_code' => 'buyer_stage_undo_request_pending',
            ];
        }
        if (SellerStageUndoSupport::findOpenUndoRequestForLot($lot) !== null) {
            return [
                'message' => 'Cannot open a buyer undo request while a seller undo request is pending.',
                'error_code' => 'stage_undo_request_pending',
            ];
        }
        if (trim((string) ($dealTagData['confirm_code'] ?? '')) !== '') {
            return [
                'message' => 'confirm_code is assigned by the server on buyer_stage_undo_request.',
                'error_code' => 'deal_tag_invalid',
            ];
        }

        return self::validateUndoStepAllowed($lot, $dealTagData);
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    public static function validateSellerStageUndoConfirm(ExchangeLot $lot, array $dealTagData): ?array
    {
        $matchError = self::validateSellerResponseToOpenUndoRequest($lot, $dealTagData, 'seller_stage_undo_confirm');
        if ($matchError !== null) {
            return $matchError;
        }

        return self::validateUndoStepAllowed($lot, $dealTagData);
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    public static function validateSellerStageUndoReject(ExchangeLot $lot, array $dealTagData): ?array
    {
        return self::validateSellerResponseToOpenUndoRequest($lot, $dealTagData, 'seller_stage_undo_reject');
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    private static function validateSellerResponseToOpenUndoRequest(
        ExchangeLot $lot,
        array $dealTagData,
        string $tagType,
    ): ?array {
        $step = trim((string) ($dealTagData['step'] ?? ''));
        $code = trim((string) ($dealTagData['confirm_code'] ?? ''));
        if ($step === '' || $code === '') {
            return [
                'message' => $tagType.' must include non-empty "step" and "confirm_code".',
                'error_code' => 'deal_tag_invalid',
            ];
        }
        $open = self::findOpenUndoRequestForLot($lot);
        if ($open === null) {
            return [
                'message' => 'No pending buyer undo request for this deal.',
                'error_code' => 'buyer_stage_undo_no_pending_request',
            ];
        }
        if ($open['step'] !== $step || $open['confirm_code'] !== $code) {
            return [
                'message' => 'confirm_code or step does not match the open undo request.',
                'error_code' => 'buyer_stage_undo_response_mismatch',
            ];
        }

        return null;
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
            self::STEP_BUYER_PAYMENT_PROOF,
            self::STEP_BUYER_DESTINATION_DETAILS,
            self::STEP_DEAL_COMPLETED_BUYER,
        ], true)) {
            return [
                'message' => 'Unknown buyer undo step.',
                'error_code' => 'deal_tag_invalid',
            ];
        }

        $state = DealChatTagSequenceValidator::computeStateForLot($lot);

        return match ($step) {
            self::STEP_BUYER_PAYMENT_PROOF => self::validateUndoBuyerPaymentProof($state),
            self::STEP_BUYER_DESTINATION_DETAILS => self::validateUndoBuyerDestinationDetails($state),
            self::STEP_DEAL_COMPLETED_BUYER => self::validateUndoDealCompletedBuyer($state),
            default => [
                'message' => 'Unexpected buyer undo step.',
                'error_code' => 'deal_tag_invalid',
            ],
        };
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    private static function validateUndoBuyerPaymentProof(string $state): ?array
    {
        if ($state !== DealChatTagSequenceValidator::STATE_WAIT_SELLER_CONFIRM) {
            return [
                'message' => 'Can undo buyer payment proof only while the seller has not yet confirmed receiving funds.',
                'error_code' => 'buyer_stage_undo_not_allowed',
            ];
        }

        return null;
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    private static function validateUndoBuyerDestinationDetails(string $state): ?array
    {
        if ($state !== DealChatTagSequenceValidator::STATE_WAIT_SELLER_TRANSFER) {
            return [
                'message' => 'Can undo buyer destination details only before the seller posts transfer proof.',
                'error_code' => 'buyer_stage_undo_not_allowed',
            ];
        }

        return null;
    }

    /**
     * @return array{message: string, error_code: string}|null
     */
    private static function validateUndoDealCompletedBuyer(string $state): ?array
    {
        if ($state !== DealChatTagSequenceValidator::STATE_COMPLETED) {
            return [
                'message' => 'Can undo buyer completion only after the buyer confirmed deal completion.',
                'error_code' => 'buyer_stage_undo_not_allowed',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $obj
     */
    public static function applyTagObjectToState(string $state, array $obj): string
    {
        $t = trim((string) ($obj['t'] ?? ''));
        if ($t === self::TAG_REQUEST || $t === self::TAG_REJECT) {
            return $state;
        }

        $step = trim((string) ($obj['step'] ?? ''));
        if ($t !== self::TAG_CONFIRM) {
            return $state;
        }

        return match ($step) {
            self::STEP_BUYER_PAYMENT_PROOF => DealChatTagSequenceValidator::STATE_WAIT_BUYER_PAY,
            self::STEP_BUYER_DESTINATION_DETAILS => DealChatTagSequenceValidator::STATE_WAIT_BUYER_DEST,
            self::STEP_DEAL_COMPLETED_BUYER => DealChatTagSequenceValidator::STATE_WAIT_BUYER_COMPLETE,
            default => $state,
        };
    }

    public static function applyDatabaseSideEffects(ExchangeLot $lot, string $step): void
    {
        // Chat tags drive UI state; no mandatory DB mutation for buyer undo at this time.
    }
}

<?php

namespace App\Support;

use App\Models\DealChatMessage;
use App\Models\ExchangeLot;

/**
 * Enforces the same deal-tag progression as the Android client's DealChatTagState reducer
 * (see xboard-client docs/DEAL_IN_PROGRESS_SCREENS.md). Prevents out-of-order or forged tags
 * from being persisted via send-message APIs.
 */
final class DealChatTagSequenceValidator
{
    public const STATE_IDLE = 'idle';

    public const STATE_DEAL_STARTED = 'deal_started';

    public const STATE_WAIT_BUYER_PAY = 'wait_buyer_pay';

    public const STATE_WAIT_SELLER_CONFIRM = 'wait_seller_confirm';

    public const STATE_WAIT_BUYER_DEST = 'wait_buyer_dest';

    public const STATE_WAIT_SELLER_TRANSFER = 'wait_seller_transfer';

    public const STATE_WAIT_BUYER_COMPLETE = 'wait_buyer_complete';

    public const STATE_COMPLETED = 'completed';

    public const STATE_BUYER_EXITED = 'buyer_exited';

    public const STATE_SELLER_EXITED = 'seller_exited';

    /**
     * @param  list<string|null>  $orderedDealTagJson
     */
    public static function reduceStateFromOrderedDealTags(array $orderedDealTagJson, bool $emptyHistoryMeansDealStarted): string
    {
        $state = self::STATE_IDLE;
        foreach ($orderedDealTagJson as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $obj = json_decode($raw, true);
            if (! is_array($obj)) {
                continue;
            }
            $state = self::applyTagObjectToState($state, $obj);
        }
        if ($state === self::STATE_IDLE && $emptyHistoryMeansDealStarted) {
            return self::STATE_DEAL_STARTED;
        }

        return $state;
    }

    public static function computeStateForLot(ExchangeLot $lot): string
    {
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        $buyerTokenHash = trim((string) ($lot->buyer_escrow_token_hash ?? ''));
        // After amicable completion the API clears buyer_lot_email but keeps buyer_escrow_token_hash for chat auth.
        $fallbackStarted = $buyerEmail !== '' || $buyerTokenHash !== '';
        $tags = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->orderBy('id')
            ->pluck('deal_tag')
            ->all();

        return self::reduceStateFromOrderedDealTags($tags, $fallbackStarted);
    }

    /**
     * @param  array<string, mixed>  $dealTagData
     * @return array{message: string, error_code: string}|null
     */
    public static function validateBuyerCustomDealTag(ExchangeLot $lot, array $dealTagData): ?array
    {
        $lotIdStr = (string) $lot->id;
        if (! self::tagLotIdMatches($dealTagData, $lotIdStr)) {
            return [
                'message' => 'deal_tag lot_id does not match this lot.',
                'error_code' => 'deal_tag_lot_mismatch',
            ];
        }
        $t = isset($dealTagData['t']) ? trim((string) $dealTagData['t']) : '';
        if ($t === '') {
            return [
                'message' => 'deal_tag must include a non-empty type field "t".',
                'error_code' => 'deal_tag_invalid',
            ];
        }
        $state = self::computeStateForLot($lot);
        if (self::isDisputeTag($t)) {
            return self::validateBuyerDisputeTagForState($state);
        }
        if ($t === SellerStageUndoSupport::TAG_CONFIRM) {
            return SellerStageUndoSupport::validateBuyerStageUndoConfirm($lot, $dealTagData);
        }
        if ($t === SellerStageUndoSupport::TAG_REJECT) {
            return SellerStageUndoSupport::validateBuyerStageUndoReject($lot, $dealTagData);
        }
        if ($t === BuyerStageUndoSupport::TAG_REQUEST) {
            return BuyerStageUndoSupport::validateBuyerStageUndoRequest($lot, $dealTagData);
        }
        $allowedBuyer = [
            'buyer_message',
            'receipt_confirmation',
            'buyer_crypto_tx',
            'buyer_destination_details',
            'deal_completed_buyer',
        ];
        if (! in_array($t, $allowedBuyer, true)) {
            return [
                'message' => 'This deal_tag type is not allowed from the buyer on this endpoint.',
                'error_code' => 'deal_tag_not_allowed_for_sender',
            ];
        }

        // Seller crypto payout posts deal_completed_seller (crypto_payout_tx) and waits for buyer confirm.
        if ($t === 'deal_completed_buyer' && self::lotAwaitingBuyerCompletionConfirm($lot)) {
            return null;
        }

        return self::validateBuyerTagForState($state, $t);
    }

    /**
     * True when seller already posted crypto payout completion but buyer has not confirmed yet.
     */
    public static function lotAwaitingBuyerCompletionConfirm(ExchangeLot $lot): bool
    {
        $tags = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereNotNull('deal_tag')
            ->orderBy('id')
            ->pluck('deal_tag')
            ->all();

        return self::tagsIndicateAwaitingBuyerCompletionConfirm($tags, (string) $lot->id);
    }

    /**
     * @param  list<string|null>  $orderedDealTagJson
     */
    public static function tagsIndicateAwaitingBuyerCompletionConfirm(array $orderedDealTagJson, string $lotIdStr): bool
    {
        $sellerCryptoDone = false;
        $buyerDone = false;
        foreach ($orderedDealTagJson as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $obj = json_decode($raw, true);
            if (! is_array($obj)) {
                continue;
            }
            if ((string) ($obj['lot_id'] ?? '') !== $lotIdStr) {
                continue;
            }
            $t = trim((string) ($obj['t'] ?? ''));
            if ($t === 'deal_completed_buyer') {
                $buyerDone = true;
            }
            if ($t === 'deal_completed_seller' && trim((string) ($obj['crypto_payout_tx'] ?? '')) !== '') {
                $sellerCryptoDone = true;
            }
        }

        return $sellerCryptoDone && ! $buyerDone;
    }

    /**
     * @param  array<string, mixed>  $dealTagData
     * @return array{message: string, error_code: string}|null
     */
    public static function validateSellerCustomDealTag(ExchangeLot $lot, array $dealTagData): ?array
    {
        $lotIdStr = (string) $lot->id;
        if (! self::tagLotIdMatches($dealTagData, $lotIdStr)) {
            return [
                'message' => 'deal_tag lot_id does not match this lot.',
                'error_code' => 'deal_tag_lot_mismatch',
            ];
        }
        $t = isset($dealTagData['t']) ? trim((string) $dealTagData['t']) : '';
        if ($t === '') {
            return [
                'message' => 'deal_tag must include a non-empty type field "t".',
                'error_code' => 'deal_tag_invalid',
            ];
        }
        $state = self::computeStateForLot($lot);
        if (self::isDisputeTag($t)) {
            return self::validateSellerDisputeTagForState($state);
        }
        if ($t === SellerStageUndoSupport::TAG_LEGACY) {
            return SellerStageUndoSupport::rejectLegacyDirectUndo();
        }
        if ($t === SellerStageUndoSupport::TAG_REQUEST) {
            return SellerStageUndoSupport::validateSellerStageUndoRequest($lot, $dealTagData);
        }
        if ($t === BuyerStageUndoSupport::TAG_CONFIRM) {
            return BuyerStageUndoSupport::validateSellerStageUndoConfirm($lot, $dealTagData);
        }
        if ($t === BuyerStageUndoSupport::TAG_REJECT) {
            return BuyerStageUndoSupport::validateSellerStageUndoReject($lot, $dealTagData);
        }
        $allowedSeller = [
            'seller_message',
            'seller_received_funds',
            'seller_transfer_tx',
            'deal_completed_seller_timeout',
        ];
        if (! in_array($t, $allowedSeller, true)) {
            return [
                'message' => 'This deal_tag type is not allowed from the seller on this endpoint.',
                'error_code' => 'deal_tag_not_allowed_for_sender',
            ];
        }

        return self::validateSellerTagForState($state, $t);
    }

    public static function isDisputeTag(string $t): bool
    {
        return in_array($t, ['dispute_requested', 'dispute_requested_by_buyer', 'dispute_requested_by_seller'], true);
    }

    /**
     * Buyer is allowed to open dispute only after first-leg payment proof is already posted.
     *
     * @return array{message: string, error_code: string}|null
     */
    public static function validateBuyerDisputeTagForState(string $state): ?array
    {
        $allowed = [
            self::STATE_WAIT_SELLER_CONFIRM,
            self::STATE_WAIT_BUYER_DEST,
            self::STATE_WAIT_SELLER_TRANSFER,
            self::STATE_WAIT_BUYER_COMPLETE,
        ];
        if (in_array($state, $allowed, true)) {
            return null;
        }

        return [
            'message' => 'Buyer can open dispute only after posting first-leg payment proof.',
            'error_code' => 'dispute_not_allowed_before_buyer_payment_proof',
        ];
    }

    /**
     * Seller is allowed to open dispute only after buyer first-leg payment proof stage starts.
     *
     * @return array{message: string, error_code: string}|null
     */
    public static function validateSellerDisputeTagForState(string $state): ?array
    {
        $allowed = [
            self::STATE_WAIT_SELLER_CONFIRM,
            self::STATE_WAIT_BUYER_DEST,
            self::STATE_WAIT_SELLER_TRANSFER,
            self::STATE_WAIT_BUYER_COMPLETE,
        ];
        if (in_array($state, $allowed, true)) {
            return null;
        }

        return [
            'message' => 'Seller can open dispute only after buyer first-leg payment proof is present.',
            'error_code' => 'dispute_not_allowed_before_buyer_payment_proof',
        ];
    }

    /**
     * @param  array<string, mixed>  $dealTagData
     */
    public static function tagLotIdMatches(array $dealTagData, string $lotIdStr): bool
    {
        if (! array_key_exists('lot_id', $dealTagData)) {
            return true;
        }
        $tagLot = trim((string) $dealTagData['lot_id']);

        return $tagLot !== '' && $tagLot === $lotIdStr;
    }

    /**
     * @param  array<string, mixed>  $obj
     */
    private static function applyTagObjectToState(string $state, array $obj): string
    {
        $t = isset($obj['t']) ? trim((string) $obj['t']) : '';
        if (self::isDisputeTag($t)) {
            return $state;
        }
        switch ($t) {
            case 'start_deal':
                return self::STATE_DEAL_STARTED;
            case 'payment_details':
                return self::STATE_WAIT_BUYER_PAY;
            case 'receipt_confirmation':
            case 'buyer_crypto_tx':
                return self::STATE_WAIT_SELLER_CONFIRM;
            case 'seller_received_funds':
                return self::STATE_WAIT_BUYER_DEST;
            case 'buyer_destination_details':
                return self::STATE_WAIT_SELLER_TRANSFER;
            case 'seller_transfer_tx':
                return self::STATE_WAIT_BUYER_COMPLETE;
            case 'deal_completed_seller':
                $cryptoPayoutTx = trim((string) ($obj['crypto_payout_tx'] ?? ''));

                return $cryptoPayoutTx !== ''
                    ? self::STATE_WAIT_BUYER_COMPLETE
                    : self::STATE_COMPLETED;
            case 'deal_completed_seller_timeout':
            case 'deal_completed_buyer':
                return self::STATE_COMPLETED;
            case 'buyer_exited':
                return self::STATE_BUYER_EXITED;
            case 'seller_exited':
                return self::STATE_SELLER_EXITED;
            case SellerStageUndoSupport::TAG_REQUEST:
            case SellerStageUndoSupport::TAG_CONFIRM:
            case SellerStageUndoSupport::TAG_REJECT:
            case SellerStageUndoSupport::TAG_LEGACY:
                return SellerStageUndoSupport::applyTagObjectToState($state, $obj);
            case BuyerStageUndoSupport::TAG_REQUEST:
            case BuyerStageUndoSupport::TAG_CONFIRM:
            case BuyerStageUndoSupport::TAG_REJECT:
                return BuyerStageUndoSupport::applyTagObjectToState($state, $obj);
            default:
                return $state;
        }
    }

    /**
     * @return array{message: string, error_code: string}|null
     *
     * @internal Used by unit tests; prefer validateBuyerCustomDealTag in production.
     */
    public static function validateBuyerTagForState(string $state, string $t): ?array
    {
        if ($t === 'buyer_message') {
            if (in_array($state, [self::STATE_BUYER_EXITED, self::STATE_SELLER_EXITED], true)) {
                return [
                    'message' => 'Free-form buyer messages are not allowed after this deal was exited.',
                    'error_code' => 'deal_tag_out_of_sequence',
                ];
            }

            return null;
        }
        if (in_array($state, [self::STATE_COMPLETED, self::STATE_BUYER_EXITED, self::STATE_SELLER_EXITED], true)) {
            return [
                'message' => 'This deal step is already finished; this deal_tag is not allowed.',
                'error_code' => 'deal_tag_out_of_sequence',
            ];
        }
        if ($t === 'receipt_confirmation') {
            if ($state === self::STATE_WAIT_BUYER_PAY || $state === self::STATE_WAIT_SELLER_CONFIRM) {
                return null;
            }

            return [
                'message' => 'Bank receipt confirmation is only allowed before the seller confirms receiving your first payment.',
                'error_code' => 'deal_tag_out_of_sequence',
            ];
        }
        if ($t === 'buyer_crypto_tx') {
            if ($state !== self::STATE_WAIT_BUYER_PAY) {
                return [
                    'message' => 'Buyer crypto proof is only allowed after the seller posted payment details for the first leg.',
                    'error_code' => 'deal_tag_out_of_sequence',
                ];
            }

            return null;
        }
        if ($t === 'buyer_destination_details') {
            if ($state !== self::STATE_WAIT_BUYER_DEST) {
                return [
                    'message' => 'Buyer destination details are only allowed after the seller confirmed receiving the first payment.',
                    'error_code' => 'deal_tag_out_of_sequence',
                ];
            }

            return null;
        }
        if ($t === 'deal_completed_buyer') {
            if ($state !== self::STATE_WAIT_BUYER_COMPLETE) {
                return [
                    'message' => 'Buyer completion is only allowed after the seller posted payout proof (or server crypto payout) for the second leg.',
                    'error_code' => 'deal_tag_out_of_sequence',
                ];
            }

            return null;
        }

        return [
            'message' => 'Unexpected deal_tag validation branch.',
            'error_code' => 'deal_tag_out_of_sequence',
        ];
    }

    /**
     * @return array{message: string, error_code: string}|null
     *
     * @internal Used by unit tests; prefer validateSellerCustomDealTag in production.
     */
    public static function validateSellerTagForState(string $state, string $t): ?array
    {
        if ($t === 'seller_message') {
            if (in_array($state, [self::STATE_BUYER_EXITED, self::STATE_SELLER_EXITED], true)) {
                return [
                    'message' => 'Free-form seller messages are not allowed after this deal was exited.',
                    'error_code' => 'deal_tag_out_of_sequence',
                ];
            }

            return null;
        }
        if (in_array($state, [self::STATE_COMPLETED, self::STATE_BUYER_EXITED, self::STATE_SELLER_EXITED], true)) {
            return [
                'message' => 'This deal step is already finished; this deal_tag is not allowed.',
                'error_code' => 'deal_tag_out_of_sequence',
            ];
        }
        if ($t === 'seller_received_funds') {
            if ($state !== self::STATE_WAIT_SELLER_CONFIRM) {
                return [
                    'message' => 'Seller “funds received” is only allowed after the buyer posted first-leg payment proof.',
                    'error_code' => 'deal_tag_out_of_sequence',
                ];
            }

            return null;
        }
        if ($t === 'seller_transfer_tx') {
            if ($state !== self::STATE_WAIT_SELLER_TRANSFER && $state !== self::STATE_WAIT_BUYER_COMPLETE) {
                return [
                    'message' => 'Seller transfer proof is only allowed after the buyer posted destination details for the second leg, or again while waiting for buyer completion to resend proof.',
                    'error_code' => 'deal_tag_out_of_sequence',
                ];
            }

            return null;
        }
        if ($t === 'deal_completed_seller_timeout') {
            if ($state !== self::STATE_WAIT_BUYER_COMPLETE) {
                return [
                    'message' => 'Seller timeout completion is only allowed while waiting for the buyer to confirm completion.',
                    'error_code' => 'deal_tag_out_of_sequence',
                ];
            }

            return null;
        }

        return [
            'message' => 'Unexpected deal_tag validation branch.',
            'error_code' => 'deal_tag_out_of_sequence',
        ];
    }
}

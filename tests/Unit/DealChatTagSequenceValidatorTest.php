<?php

namespace Tests\Unit;

use App\Support\DealChatTagSequenceValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @see docs/SPEC_REGISTRY.md LOT-004
 */
#[Group('spec')]
#[Group('spec-LOT-004')]
class DealChatTagSequenceValidatorTest extends TestCase
{
    public function test_reduce_state_happy_path_matches_client_order(): void
    {
        $tags = [
            json_encode(['t' => 'start_deal', 'lot_id' => 1]),
            json_encode(['t' => 'payment_details', 'lot_id' => 1, 'tx_id' => 10, 'bank_id' => 2]),
            json_encode(['t' => 'receipt_confirmation', 'lot_id' => 1]),
            json_encode(['t' => 'seller_received_funds', 'lot_id' => 1]),
            json_encode(['t' => 'buyer_destination_details', 'lot_id' => 1, 'address' => 'addr']),
            json_encode(['t' => 'seller_transfer_tx', 'lot_id' => 1, 'tx_number' => 'abc']),
            json_encode(['t' => 'deal_completed_buyer', 'lot_id' => 1]),
        ];
        $state = DealChatTagSequenceValidator::reduceStateFromOrderedDealTags($tags, false);
        $this->assertSame(DealChatTagSequenceValidator::STATE_COMPLETED, $state);
    }

    public function test_reduce_state_deal_completed_seller_with_crypto_payout_waits_for_buyer(): void
    {
        $tags = [
            json_encode(['t' => 'start_deal', 'lot_id' => 5]),
            json_encode(['t' => 'payment_details', 'lot_id' => 5, 'crypto' => 'xmr', 'address' => 'x']),
            json_encode(['t' => 'buyer_crypto_tx', 'lot_id' => 5]),
            json_encode(['t' => 'seller_received_funds', 'lot_id' => 5]),
            json_encode(['t' => 'buyer_destination_details', 'lot_id' => 5, 'address' => 'dest']),
            json_encode(['t' => 'deal_completed_seller', 'lot_id' => 5, 'crypto_payout_tx' => '0xdead']),
        ];
        $state = DealChatTagSequenceValidator::reduceStateFromOrderedDealTags($tags, false);
        $this->assertSame(DealChatTagSequenceValidator::STATE_WAIT_BUYER_COMPLETE, $state);
    }

    public function test_empty_history_with_active_buyer_falls_back_to_started(): void
    {
        $state = DealChatTagSequenceValidator::reduceStateFromOrderedDealTags([], true);
        $this->assertSame(DealChatTagSequenceValidator::STATE_DEAL_STARTED, $state);
    }

    #[DataProvider('buyerSequenceViolations')]
    public function test_buyer_tag_rejected_out_of_sequence(string $state, string $tagType): void
    {
        $err = DealChatTagSequenceValidator::validateBuyerTagForState($state, $tagType);
        $this->assertNotNull($err);
        $this->assertSame('deal_tag_out_of_sequence', $err['error_code']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function buyerSequenceViolations(): iterable
    {
        yield 'receipt before payment_details' => [
            DealChatTagSequenceValidator::STATE_DEAL_STARTED,
            'receipt_confirmation',
        ];
        yield 'destination before seller confirmed funds' => [
            DealChatTagSequenceValidator::STATE_WAIT_SELLER_CONFIRM,
            'buyer_destination_details',
        ];
        yield 'completion before seller payout' => [
            DealChatTagSequenceValidator::STATE_WAIT_SELLER_TRANSFER,
            'deal_completed_buyer',
        ];
    }

    #[DataProvider('sellerSequenceViolations')]
    public function test_seller_tag_rejected_out_of_sequence(string $state, string $tagType): void
    {
        $err = DealChatTagSequenceValidator::validateSellerTagForState($state, $tagType);
        $this->assertNotNull($err);
        $this->assertSame('deal_tag_out_of_sequence', $err['error_code']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sellerSequenceViolations(): iterable
    {
        yield 'seller_received before buyer proof' => [
            DealChatTagSequenceValidator::STATE_WAIT_BUYER_PAY,
            'seller_received_funds',
        ];
        yield 'seller_transfer before destination' => [
            DealChatTagSequenceValidator::STATE_WAIT_BUYER_DEST,
            'seller_transfer_tx',
        ];
        yield 'timeout completion before payout leg' => [
            DealChatTagSequenceValidator::STATE_WAIT_SELLER_TRANSFER,
            'deal_completed_seller_timeout',
        ];
    }

    public function test_dispute_tag_skipped_in_reducer(): void
    {
        $tags = [
            json_encode(['t' => 'start_deal', 'lot_id' => 1]),
            json_encode(['t' => 'dispute_requested_by_buyer', 'lot_id' => 1]),
            json_encode(['t' => 'payment_details', 'lot_id' => 1, 'bank_id' => 2]),
        ];
        $state = DealChatTagSequenceValidator::reduceStateFromOrderedDealTags($tags, false);
        $this->assertSame(DealChatTagSequenceValidator::STATE_WAIT_BUYER_PAY, $state);
    }

    public function test_buyer_dispute_rejected_before_payment_proof_stage(): void
    {
        $err = DealChatTagSequenceValidator::validateBuyerDisputeTagForState(
            DealChatTagSequenceValidator::STATE_WAIT_BUYER_PAY
        );
        $this->assertNotNull($err);
        $this->assertSame('dispute_not_allowed_before_buyer_payment_proof', $err['error_code']);
    }

    public function test_buyer_dispute_allowed_after_payment_proof_stage(): void
    {
        $err = DealChatTagSequenceValidator::validateBuyerDisputeTagForState(
            DealChatTagSequenceValidator::STATE_WAIT_SELLER_CONFIRM
        );
        $this->assertNull($err);
    }

    public function test_seller_dispute_rejected_before_payment_proof_stage(): void
    {
        $err = DealChatTagSequenceValidator::validateSellerDisputeTagForState(
            DealChatTagSequenceValidator::STATE_WAIT_BUYER_PAY
        );
        $this->assertNotNull($err);
        $this->assertSame('dispute_not_allowed_before_buyer_payment_proof', $err['error_code']);
    }
}

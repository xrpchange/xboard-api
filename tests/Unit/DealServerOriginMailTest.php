<?php

namespace Tests\Unit;

use App\Support\DealServerOriginMail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-025')]
class DealServerOriginMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.from.address' => 'noreply@xdexboard.com']);
    }

    public function test_payment_details_rejected_from_participant_mailbox_on_email_channel(): void
    {
        $tag = '{"t":"payment_details","lot_id":42,"address":"evil"}';
        $this->assertNull(
            DealServerOriginMail::sanitizeDealTagForEmailOrigin($tag, 'seller-lot@mail.xdexboard.com')
        );
    }

    public function test_payment_details_accepted_from_system_mail_on_email_channel(): void
    {
        $tag = '{"t":"payment_details","lot_id":42,"address":"48edf"}';
        $this->assertSame(
            $tag,
            DealServerOriginMail::sanitizeDealTagForEmailOrigin($tag, 'noreply@xdexboard.com')
        );
    }

    public function test_payment_details_chat_row_trusted_from_seller_side(): void
    {
        $tag = '{"t":"payment_details","lot_id":42}';
        $this->assertSame(
            $tag,
            DealServerOriginMail::sanitizeDealTagForChatRow($tag, 'seller')
        );
    }

    public function test_payment_details_chat_row_rejected_from_buyer_side(): void
    {
        $tag = '{"t":"payment_details","lot_id":42,"address":"evil"}';
        $this->assertNull(
            DealServerOriginMail::sanitizeDealTagForChatRow($tag, 'buyer')
        );
    }

    public function test_start_deal_chat_row_rejected_from_seller_side(): void
    {
        $tag = '{"t":"start_deal","lot_id":42}';
        $this->assertNull(
            DealServerOriginMail::sanitizeDealTagForChatRow($tag, 'seller')
        );
    }

    public function test_payment_details_chat_export_keeps_seller_mailbox_from_email(): void
    {
        $tag = '{"t":"payment_details","lot_id":42,"address":"48edf"}';
        $this->assertSame(
            $tag,
            DealServerOriginMail::trustedDealTagForChatExport(
                $tag,
                'seller',
                'seller-lot@mail.xdexboard.com',
            )
        );
    }
}

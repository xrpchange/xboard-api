<?php

namespace Tests\Unit;

use App\Models\DealChatMessage;
use App\Support\DealChatMessageVisibility;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-007')]
class DealChatMessageVisibilityTest extends TestCase
{
    public function test_clearable_by_requester_excludes_deal_tag_rows(): void
    {
        $sql = DealChatMessageVisibility::applyClearableByRequester(
            DealChatMessage::query(),
            true,
        )->toSql();

        $this->assertStringContainsString('deleted_by_seller_at', $sql);
        $this->assertStringContainsString('deal_tag', $sql);
    }
}

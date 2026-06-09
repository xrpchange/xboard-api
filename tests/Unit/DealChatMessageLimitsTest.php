<?php

namespace Tests\Unit;

use App\Support\DealChatMessageLimits;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DealChatMessageLimitsTest extends TestCase
{
    public function test_message_validation_rule_uses_configured_max_length(): void
    {
        Config::set('deal.max_message_length', 1000);

        $this->assertSame('required|string|max:1000', DealChatMessageLimits::messageValidationRule());
        $this->assertSame('nullable|string|max:1000', DealChatMessageLimits::messageValidationRule(required: false));
    }
}

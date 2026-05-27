<?php

namespace Tests\Unit;

use App\Support\DealSellerOpenLinks;
use PHPUnit\Framework\TestCase;

class DealSellerOpenLinksTest extends TestCase
{
    public function test_app_deep_link_builds_query(): void
    {
        $url = DealSellerOpenLinks::appDeepLink(['lot_id' => '42', 'node_key' => 'xdexboard.com']);

        $this->assertSame('xboard://deal/open?lot_id=42&node_key=xdexboard.com', $url);
    }

    public function test_android_intent_uri_for_landing_page_only(): void
    {
        $url = DealSellerOpenLinks::androidIntentUri(
            ['lot_id' => '42', 'node_key' => 'xdexboard.com'],
        );

        $this->assertStringStartsWith('intent://deal/open?', $url);
        $this->assertStringContainsString('scheme=xboard', $url);
        $this->assertStringContainsString('package=com.xdexboard.xboard', $url);
        $this->assertStringNotContainsString('browser_fallback_url', $url);
        $this->assertStringEndsWith(';end', $url);
    }
}

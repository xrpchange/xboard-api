<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * @see docs/SPEC_REGISTRY.md LOT-001
 */
#[Group('spec')]
#[Group('spec-LOT-001')]
class PurgeCompletedDealRouteTest extends TestCase
{
    public function test_spec_lot_001_purge_completed_deal_post_route_is_registered(): void
    {
        $found = collect(app('router')->getRoutes())->first(function ($route) {
            return in_array('POST', $route->methods(), true)
                && str_contains($route->uri(), 'exchange-lots')
                && str_contains($route->uri(), 'purge-completed-deal-data');
        });
        $this->assertNotNull($found, 'POST exchange-lots/{lot}/purge-completed-deal-data should be registered');
    }
}

<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * @see docs/EXCHANGE_LOT_SEARCH.md — Seller my lots (POST)
 */
#[Group('spec')]
#[Group('spec-LOT-024')]
class SellerExchangeLotsListRouteTest extends TestCase
{
    public function test_spec_lot_024_seller_list_post_route_is_registered(): void
    {
        $found = collect(app('router')->getRoutes())->first(function ($route) {
            return in_array('POST', $route->methods(), true)
                && str_contains($route->uri(), 'exchange-lots')
                && str_contains($route->uri(), 'seller-list');
        });
        $this->assertNotNull($found, 'POST exchange-lots/seller-list should be registered');
    }
}

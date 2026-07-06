<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\ExchangeLotTestMainFilter;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-024')]
class ExchangeLotTestMainFilterTest extends TestCase
{
    public function test_json_boolean_true_selects_test_scope(): void
    {
        $request = Request::create('/api/v1/exchange-lots/seller-list', 'POST', [
            'is_test' => true,
        ]);

        $this->assertTrue(ExchangeLotTestMainFilter::isTestRequested($request));

        $query = ExchangeLot::query();
        ExchangeLotTestMainFilter::applyFromRequest($query, $request, sellerWalletHashQuery: false);
        $sql = $query->toSql();

        $this->assertStringContainsString('is_test', $sql);
        $this->assertTrue(collect($query->getBindings())->contains(true));
    }

    public function test_json_boolean_false_selects_main_scope(): void
    {
        $request = Request::create('/api/v1/exchange-lots/seller-list', 'POST', [
            'is_test' => false,
        ]);

        $this->assertFalse(ExchangeLotTestMainFilter::isTestRequested($request));

        $query = ExchangeLot::query();
        ExchangeLotTestMainFilter::applyFromRequest($query, $request, sellerWalletHashQuery: false);
        $sql = $query->toSql();

        $this->assertStringContainsString('is_test', $sql);
        $this->assertTrue(collect($query->getBindings())->contains(false));
    }

    public function test_seller_wallet_hash_query_skips_test_main_filter(): void
    {
        $request = Request::create('/api/v1/exchange-lots/seller-list', 'POST', [
            'is_test' => false,
            'wallet_hashes' => ['abc'],
        ]);

        $query = ExchangeLot::query();
        ExchangeLotTestMainFilter::applyFromRequest($query, $request, sellerWalletHashQuery: true);

        $this->assertStringNotContainsString('is_test', $query->toSql());
    }
}

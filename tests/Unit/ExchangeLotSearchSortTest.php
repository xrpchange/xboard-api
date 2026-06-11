<?php

namespace Tests\Unit;

use App\Support\ExchangeLotSearchSort;
use Illuminate\Http\Request;
use Tests\TestCase;

class ExchangeLotSearchSortTest extends TestCase
{
    /** @group spec */
    /** @group spec-LOT-020 */
    public function test_default_sort_and_page_size(): void
    {
        config(['lot.search_page_size' => 50, 'lot.search_page_size_max' => 100]);
        $request = Request::create('/api/v1/exchange-lots', 'GET');

        [$sortBy, $sortDir] = ExchangeLotSearchSort::resolveFromRequest($request);
        $this->assertSame('updated_at', $sortBy);
        $this->assertSame('desc', $sortDir);
        $this->assertSame(50, ExchangeLotSearchSort::perPageFromRequest($request));
    }

    /** @group spec */
    /** @group spec-LOT-020 */
    public function test_completed_deals_sort_and_per_page_cap(): void
    {
        config(['lot.search_page_size' => 50, 'lot.search_page_size_max' => 100]);
        $request = Request::create('/api/v1/exchange-lots', 'GET', [
            'sort_by' => 'completed_deals_count',
            'sort_dir' => 'desc',
            'per_page' => '500',
        ]);

        [$sortBy, $sortDir] = ExchangeLotSearchSort::resolveFromRequest($request);
        $this->assertSame('completed_deals_count', $sortBy);
        $this->assertSame('desc', $sortDir);
        $this->assertSame(100, ExchangeLotSearchSort::perPageFromRequest($request));
    }

    /** @group spec */
    /** @group spec-LOT-020 */
    public function test_invalid_sort_falls_back(): void
    {
        $request = Request::create('/api/v1/exchange-lots', 'GET', [
            'sort_by' => 'rate',
            'sort_dir' => 'sideways',
        ]);

        [$sortBy, $sortDir] = ExchangeLotSearchSort::resolveFromRequest($request);
        $this->assertSame('updated_at', $sortBy);
        $this->assertSame('desc', $sortDir);
    }
}

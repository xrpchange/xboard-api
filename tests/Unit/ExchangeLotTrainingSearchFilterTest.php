<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\ExchangeLotTrainingSearchFilter;
use Illuminate\Http\Request;
use Tests\TestCase;

class ExchangeLotTrainingSearchFilterTest extends TestCase
{
    /** @group spec */
    /** @group spec-LOT-023 */
    public function test_default_search_excludes_training_lots(): void
    {
        $query = ExchangeLot::query();
        $request = Request::create('/api/v1/exchange-lots', 'GET');

        $shouldRelease = ExchangeLotTrainingSearchFilter::applyFromRequest($query, $request);

        $this->assertFalse($shouldRelease);
        $this->assertContains(false, $query->getBindings());
    }

    /** @group spec */
    /** @group spec-LOT-023 */
    public function test_explicit_true_lists_training_only_and_triggers_release(): void
    {
        $query = ExchangeLot::query();
        $request = Request::create('/api/v1/exchange-lots', 'GET', ['is_training' => 'true']);

        $shouldRelease = ExchangeLotTrainingSearchFilter::applyFromRequest($query, $request);

        $this->assertTrue($shouldRelease);
        $this->assertContains(true, $query->getBindings());
    }

    /** @group spec */
    /** @group spec-LOT-023 */
    public function test_explicit_false_excludes_training_lots(): void
    {
        $query = ExchangeLot::query();
        $request = Request::create('/api/v1/exchange-lots', 'GET', ['is_training' => 'false']);

        $shouldRelease = ExchangeLotTrainingSearchFilter::applyFromRequest($query, $request);

        $this->assertFalse($shouldRelease);
        $this->assertContains(false, $query->getBindings());
    }
}

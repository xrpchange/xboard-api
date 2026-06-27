<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Services\Deal\TrainingDealOrchestrator;
use App\Support\ApiDeploymentEnvironment;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/** @group spec */
class TrainingDealOrchestratorTest extends TestCase
{
    /** @group spec-LOT-021 */
    public function test_disabled_when_not_training_lot(): void
    {
        Config::set('training_deal.enabled', true);
        Config::set('app.url', 'https://sandbox.api.xdexboard.com');

        $lot = new ExchangeLot(['is_training' => false]);
        $orch = $this->app->make(TrainingDealOrchestrator::class);

        $this->assertFalse($orch->isEnabledForLot($lot));
    }

    /** @group spec-LOT-021 */
    public function test_enabled_on_sandbox_training_lot(): void
    {
        Config::set('training_deal.enabled', true);
        Config::set('app.url', 'https://sandbox.api.xdexboard.com');

        $lot = new ExchangeLot(['is_training' => true]);
        $orch = $this->app->make(TrainingDealOrchestrator::class);

        $this->assertTrue($orch->isEnabledForLot($lot));
        $this->assertSame(ApiDeploymentEnvironment::SANDBOX, ApiDeploymentEnvironment::current());
    }

    /** @group spec-LOT-021 */
    public function test_advance_accepts_start_deal_tag(): void
    {
        Config::set('training_deal.enabled', true);
        Config::set('app.url', 'https://sandbox.api.xdexboard.com');

        $lot = new ExchangeLot(['is_training' => true, 'id' => 99]);
        $orch = $this->app->make(TrainingDealOrchestrator::class);
        $orch->advanceAfterBuyerAction($lot, ['t' => 'start_deal', 'lot_id' => 99]);
        $this->addToAssertionCount(1);
    }

    /** @group spec-LOT-021 */
    public function test_bypass_core_tx_verification_for_training_buyer_crypto_tx(): void
    {
        Config::set('training_deal.enabled', true);
        Config::set('app.url', 'https://sandbox.api.xdexboard.com');

        $lot = new ExchangeLot(['is_training' => true, 'id' => 9]);
        $orch = $this->app->make(TrainingDealOrchestrator::class);

        $out = $orch->bypassCoreTxVerificationForTag($lot, [
            't' => 'buyer_crypto_tx',
            'lot_id' => 9,
            'tx_ref' => 'stellar-test-tx-hash',
        ]);

        $this->assertTrue($out['core_tx_check']['verified'] ?? false);
        $this->assertTrue($out['core_tx_check']['training_auto'] ?? false);
    }

    /** @group spec-LOT-021 */
    public function test_disabled_on_production_host_even_when_training_flag(): void
    {
        Config::set('training_deal.enabled', true);
        Config::set('app.url', 'https://api.xdexboard.com');

        $lot = new ExchangeLot(['is_training' => true]);
        $orch = $this->app->make(TrainingDealOrchestrator::class);

        $this->assertFalse($orch->isEnabledForLot($lot));
    }
}

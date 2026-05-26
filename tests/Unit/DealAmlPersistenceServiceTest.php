<?php

namespace Tests\Unit;

use App\Services\DealAmlPersistenceService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-AML-001')]
class DealAmlPersistenceServiceTest extends TestCase
{
    private DealAmlPersistenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DealAmlPersistenceService;
    }

    public function test_sum_token_charges_in_aml_state(): void
    {
        $state = [
            'token_charges' => [
                [
                    'charge_key' => 'a',
                    'charged_token_hash' => 'abc',
                    'amount' => 0.5,
                ],
                [
                    'charge_key' => 'b',
                    'charged_token_hash' => 'other',
                    'amount' => 9.0,
                ],
                [
                    'charge_key' => 'c',
                    'charged_token_hash' => 'ABC',
                    'amount' => 1.0,
                ],
            ],
        ];

        $this->assertSame(1.5, $this->service->sumTokenChargesInAmlState($state, 'abc'));
    }

    public function test_seller_fail_prior_buyer_charge_amount_from_buyer_screening_in_state(): void
    {
        $state = [
            'buyer_screening' => [
                'passed' => true,
                'cost_amount' => 0.25,
            ],
            'token_charges' => [],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('appendFailedScreeningCharges');
        $method->setAccessible(true);

        /** @var array<int, array<string, mixed>> $charges */
        $charges = $method->invoke(
            $this->service,
            [],
            42,
            101,
            'seller',
            'sellerhash',
            'usdt_tron',
            [
                'status' => 'failed',
                'cost_amount' => 0.4,
                'check_uid' => 'uid-seller',
            ],
            'seller-addr',
            $state,
        );

        $this->assertCount(2, $charges);
        $total = $this->service->sumTokenChargesInAmlState(['token_charges' => $charges], 'sellerhash');
        $this->assertSame(0.65, $total);
    }

    public function test_provider_failure_is_not_user_fault(): void
    {
        $this->assertFalse($this->service->isUserFaultAmlFailure('AML_PROVIDER_UNAVAILABLE', [
            'reason_code' => 'aml_provider_error',
        ]));
        $this->assertTrue($this->service->isUserFaultAmlFailure('AML_CHECK_FAILED', [
            'reason_code' => 'aml_risk_threshold_exceeded',
        ]));
    }

    public function test_charge_keys_are_idempotent_when_merged(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('appendFailedScreeningCharges');
        $method->setAccessible(true);

        $existing = [
            [
                'charge_key' => 'buyer-fail:1:2:x',
                'charged_token_hash' => 'tok',
                'amount' => 1.0,
            ],
        ];

        /** @var array<int, array<string, mixed>> $charges */
        $charges = $method->invoke(
            $this->service,
            $existing,
            1,
            2,
            'buyer',
            'tok',
            'usdt_tron',
            ['status' => 'failed', 'cost_amount' => 99.0, 'check_uid' => 'x'],
            null,
            [],
        );

        $this->assertCount(1, $charges);
        $this->assertSame(1.0, (float) $charges[0]['amount']);
    }
}

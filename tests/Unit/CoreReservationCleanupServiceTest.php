<?php

namespace Tests\Unit;

use App\Models\Transaction;
use App\Services\CoreReservationCleanupService;
use App\Services\EscrowTokenValidationService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * @see docs/SPEC_REGISTRY.md LOT-017
 */
#[Group('spec')]
#[Group('spec-LOT-017')]
class CoreReservationCleanupServiceTest extends TestCase
{
    public function test_spec_lot_017_deleting_transaction_calls_core_delete_by_transaction_id(): void
    {
        config([
            'services.core.timeout' => 5,
        ]);

        Http::fake([
            'https://sandbox.api.core.xdexboard.com/api/delete-reservations-by-transaction-id' => Http::response([
                'data' => ['deleted' => 1, 'transaction_id' => 99],
            ], 200),
        ]);

        $transaction = new Transaction;
        $transaction->id = 99;
        $transaction->exchange_lot_id = 7;

        $escrowValidation = $this->createMock(EscrowTokenValidationService::class);
        $service = $this->getMockBuilder(CoreReservationCleanupService::class)
            ->setConstructorArgs([$escrowValidation])
            ->onlyMethods(['resolveCoreUrlAndKeyForTransaction'])
            ->getMock();
        $service->method('resolveCoreUrlAndKeyForTransaction')
            ->with($transaction)
            ->willReturn(['https://sandbox.api.core.xdexboard.com', 'test-core-key']);

        $service->deleteReservationsForTransaction($transaction);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://sandbox.api.core.xdexboard.com/api/delete-reservations-by-transaction-id'
                && $request['transaction_id'] === 99
                && $request->hasHeader('X-Core-Key', 'test-core-key');
        });
    }
}

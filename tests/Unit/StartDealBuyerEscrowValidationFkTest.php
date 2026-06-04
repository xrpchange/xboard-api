<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\StartDealBuyerEscrowValidation;
use App\Services\EscrowTokenValidationService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-016')]
class StartDealBuyerEscrowValidationFkTest extends TestCase
{
    public function test_to_crypto_fk_forces_crypto_validation_even_if_type_heuristics_wrong(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xrp', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);

        $validator = new StartDealBuyerEscrowValidation($this->createMock(EscrowTokenValidationService::class));
        $xrpToken = json_encode([
            'currency' => 'XRP',
            'wallet_address' => 'rBuyerWrong',
            'token_hash' => 'buyerhash',
        ], JSON_THROW_ON_ERROR);

        $result = $validator->validate($lot, $xrpToken, 50.0);

        $this->assertNotNull($result);
        $this->assertSame('buyer_escrow_token_crypto_mismatch', $result['error_code']);
    }
}

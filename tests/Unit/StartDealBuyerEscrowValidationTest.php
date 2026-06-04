<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\StartDealBuyerEscrowValidation;
use App\Services\EscrowTokenValidationService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-016')]
class StartDealBuyerEscrowValidationTest extends TestCase
{
    public function test_rejects_seller_xrp_token_when_lot_requires_usdt_payment(): void
    {
        $lot = new ExchangeLot([
            'aml' => true,
            'wallet_hash' => 'sellerhash',
            'buyer_selected_amount' => 50.0,
            'rate' => 2.0,
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xrp', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('fromFiatCurrency', null);
        $lot->setRelation('toFiatCurrency', null);

        $xrpToken = json_encode([
            'currency' => 'XRP',
            'wallet_address' => 'rBuyerWrongAssetForUsdtLot',
            'token_hash' => 'buyerxrphash',
        ], JSON_THROW_ON_ERROR);

        $validator = new StartDealBuyerEscrowValidation($this->createMock(EscrowTokenValidationService::class));
        $result = $validator->validate($lot, $xrpToken, 50.0);

        $this->assertNotNull($result);
        $this->assertSame('buyer_escrow_token_crypto_mismatch', $result['error_code']);
    }

    public function test_skips_crypto_check_when_buyer_pays_fiat_first_leg(): void
    {
        $lot = new ExchangeLot([
            'aml' => true,
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', null);

        $validator = new StartDealBuyerEscrowValidation($this->createMock(EscrowTokenValidationService::class));
        $result = $validator->validate($lot, '', 100.0);

        $this->assertNull($result);
    }
}

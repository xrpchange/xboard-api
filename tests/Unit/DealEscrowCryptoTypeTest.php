<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\DealBuyerFirstLeg;
use App\Support\DealEscrowCryptoType;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-016')]
class DealEscrowCryptoTypeTest extends TestCase
{
    public function test_xrp_to_usdt_buyer_type_is_usdt_not_seller_escrow_xrp(): void
    {
        $lot = new ExchangeLot([
            'crypto_currency_id' => 99,
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xrp', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('cryptoCurrency', (object) ['code' => 'xrp', 'is_native' => true, 'network' => 'native']);

        $this->assertSame('usdt_tron', DealEscrowCryptoType::buyerCoreType($lot));
        $this->assertSame('xrp', DealEscrowCryptoType::sellerCoreType($lot));
    }

    public function test_usdt_to_xmr_buyer_pays_xmr_seller_escrow_usdt(): void
    {
        $lot = new ExchangeLot([
            'crypto_currency_id' => 99,
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('cryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);

        $this->assertSame('xmr', DealEscrowCryptoType::buyerCoreType($lot));
        $this->assertSame('usdt_tron', DealEscrowCryptoType::sellerCoreType($lot));
    }

    public function test_usdt_to_usd_buyer_escrow_fallback_is_usdt_for_api_helpers(): void
    {
        $lot = new ExchangeLot([
            'crypto_currency_id' => 1,
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', null);
        $lot->setRelation('cryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);

        // USDT→USD field orientation: buyer pays USDT on-chain first; seller sends fiat (USD) on leg 2.
        $this->assertSame('usdt_tron', DealEscrowCryptoType::buyerCoreType($lot));
        $this->assertFalse(DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot));
        $this->assertFalse(\App\Support\DealSellerSecondLeg::sendsCrypto($lot));
    }
}

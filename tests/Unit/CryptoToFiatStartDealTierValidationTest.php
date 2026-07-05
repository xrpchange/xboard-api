<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\CryptoToFiatDealSupport;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
class CryptoToFiatStartDealTierValidationTest extends TestCase
{
    private function cryptoToFiatLot(bool $aml): ExchangeLot
    {
        $lot = new ExchangeLot([
            'aml' => $aml,
            'sell_amount' => 0.8,
            'sold_crypto_amount' => 0.0,
            'rate' => 150.0,
            'buy_amounts' => [100.0, 200.0],
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', null);
        $lot->setRelation('fromFiatCurrency', null);

        return $lot;
    }

    public function test_non_aml_crypto_to_fiat_skips_remaining_volume_tier_gate(): void
    {
        $lot = $this->cryptoToFiatLot(aml: false);

        $this->assertTrue(CryptoToFiatDealSupport::skipsStartDealRemainingVolumeTierCheck($lot));
        $this->assertSame([100.0, 200.0], $lot->configuredBuyAmounts());
        $this->assertSame([100.0], $lot->availableBuyAmountsForRemaining());
    }

    public function test_aml_crypto_to_fiat_keeps_remaining_volume_tier_gate(): void
    {
        $lot = $this->cryptoToFiatLot(aml: true);

        $this->assertFalse(CryptoToFiatDealSupport::skipsStartDealRemainingVolumeTierCheck($lot));
        $this->assertSame([100.0, 200.0], $lot->configuredBuyAmounts());
        $this->assertSame([100.0], $lot->availableBuyAmountsForRemaining());
    }

    public function test_fiat_to_crypto_never_skips_remaining_volume_tier_gate(): void
    {
        $lot = new ExchangeLot([
            'aml' => false,
            'sell_amount' => 1000.0,
            'sold_crypto_amount' => 0.0,
            'rate' => 2.0,
            'buy_amounts' => [50.0],
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('fromCryptoCurrency', null);

        $this->assertFalse(CryptoToFiatDealSupport::skipsStartDealRemainingVolumeTierCheck($lot));
    }
}

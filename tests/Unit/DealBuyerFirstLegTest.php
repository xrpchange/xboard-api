<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\DealBuyerFirstLeg;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-016')]
class DealBuyerFirstLegTest extends TestCase
{
    public function test_crypto_to_stablecoin_buyer_pays_on_chain_not_bank(): void
    {
        $lot = $this->lotWithTypes('crypto', 'stablecoin');

        $this->assertFalse(DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot));
        $this->assertTrue(DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot));
    }

    public function test_crypto_to_fiat_buyer_pays_bank(): void
    {
        $lot = $this->lotWithTypes('crypto', 'fiat');

        $this->assertTrue(DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot));
        $this->assertFalse(DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot));
    }

    public function test_fiat_to_crypto_buyer_pays_bank(): void
    {
        $lot = $this->lotWithTypes('fiat', 'crypto');

        $this->assertTrue(DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot));
        $this->assertFalse(DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot));
    }

    public function test_fiat_to_stablecoin_buyer_pays_bank(): void
    {
        $lot = $this->lotWithTypes('fiat', 'stablecoin');

        $this->assertTrue(DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot));
        $this->assertFalse(DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot));
    }

    public function test_stablecoin_to_fiat_buyer_pays_bank(): void
    {
        $lot = $this->lotWithTypes('stablecoin', 'fiat');

        $this->assertTrue(DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot));
        $this->assertFalse(DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot));
    }

    public function test_stablecoin_to_crypto_buyer_pays_on_chain(): void
    {
        $lot = $this->lotWithTypes('stablecoin', 'crypto');

        $this->assertFalse(DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot));
        $this->assertTrue(DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot));
    }

    public function test_stablecoin_to_stablecoin_buyer_pays_on_chain(): void
    {
        $lot = $this->lotWithTypes('stablecoin', 'stablecoin');

        $this->assertFalse(DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot));
        $this->assertTrue(DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot));
    }

    public function test_crypto_to_crypto_buyer_pays_on_chain(): void
    {
        $lot = $this->lotWithTypes('crypto', 'crypto');

        $this->assertFalse(DealBuyerFirstLeg::buyerFirstLegIsBankTransfer($lot));
        $this->assertTrue(DealBuyerFirstLeg::buyerFirstLegPaysCrypto($lot));
    }

    private function lotWithTypes(string $fromType, string $toType): ExchangeLot
    {
        $lot = new ExchangeLot;
        if ($fromType === 'fiat') {
            $lot->from_fiat_currency_id = 1;
            $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
            $lot->setRelation('fromCryptoCurrency', null);
        } elseif ($fromType === 'crypto') {
            $lot->from_crypto_currency_id = 1;
            $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xrp', 'is_native' => true, 'network' => 'native']);
            $lot->setRelation('fromFiatCurrency', null);
        } elseif ($fromType === 'stablecoin') {
            $lot->from_crypto_currency_id = 1;
            $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
            $lot->setRelation('fromFiatCurrency', null);
        }
        if ($toType === 'fiat') {
            $lot->to_fiat_currency_id = 2;
            $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
            $lot->setRelation('toCryptoCurrency', null);
        } elseif ($toType === 'stablecoin') {
            $lot->to_crypto_currency_id = 2;
            $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
            $lot->setRelation('toFiatCurrency', null);
        } elseif ($toType === 'crypto') {
            $lot->to_crypto_currency_id = 2;
            $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
            $lot->setRelation('toFiatCurrency', null);
        }

        return $lot;
    }
}

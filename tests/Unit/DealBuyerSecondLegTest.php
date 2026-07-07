<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\DealBuyerSecondLeg;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-016')]
class DealBuyerSecondLegTest extends TestCase
{
    public function test_xmr_to_usd_second_leg_follows_tag_for_in_flight_deals(): void
    {
        $lot = $this->lotWithTypes('crypto', 'fiat');

        // Bank tag from in-flight deal (pre-dialog-fix) → treat as buyer-receives-crypto for backward compat.
        $this->assertTrue(DealBuyerSecondLeg::buyerReceivesCrypto($lot, true));
        $this->assertFalse(DealBuyerSecondLeg::buyerReceivesCrypto($lot, false));
        $this->assertFalse(DealBuyerSecondLeg::buyerReceivesCrypto($lot, null));
    }

    public function test_usd_to_xmr_second_leg_is_crypto_when_first_leg_was_bank(): void
    {
        $lot = $this->lotWithTypes('fiat', 'crypto');

        $this->assertTrue(DealBuyerSecondLeg::buyerReceivesCrypto($lot, true));
        $this->assertFalse(DealBuyerSecondLeg::buyerReceivesCrypto($lot, false));
    }

    public function test_usdt_to_usd_second_leg_is_fiat_when_first_leg_was_crypto(): void
    {
        $lot = $this->lotWithTypes('stablecoin', 'fiat');

        $this->assertFalse(DealBuyerSecondLeg::buyerReceivesCrypto($lot, false));
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
            $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
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

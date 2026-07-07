<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\DealSellerSecondLeg;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-026')]
class DealSellerSecondLegTest extends TestCase
{
    public function test_from_crypto_seller_sends_crypto_on_second_leg(): void
    {
        $lot = $this->lotWithFromType('crypto');

        $this->assertTrue(DealSellerSecondLeg::sendsCrypto($lot));
    }

    public function test_from_fiat_seller_sends_fiat_on_second_leg(): void
    {
        $lot = $this->lotWithFromType('fiat');

        $this->assertFalse(DealSellerSecondLeg::sendsCrypto($lot));
    }

    private function lotWithFromType(string $fromType): ExchangeLot
    {
        $lot = new ExchangeLot;
        if ($fromType === 'fiat') {
            $lot->from_fiat_currency_id = 1;
            $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
            $lot->setRelation('fromCryptoCurrency', null);
        } else {
            $lot->from_crypto_currency_id = 1;
            $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
            $lot->setRelation('fromFiatCurrency', null);
        }
        $lot->to_fiat_currency_id = 2;
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);

        return $lot;
    }
}

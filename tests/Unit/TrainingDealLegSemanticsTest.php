<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\TrainingDealLegSemantics;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-021')]
class TrainingDealLegSemanticsTest extends TestCase
{
    public function test_fiat_to_stablecoin_training_legs(): void
    {
        $lot = $this->trainingLot('fiat', 'stablecoin');

        $this->assertFalse(TrainingDealLegSemantics::buyerFirstLegIsBankTransfer($lot));
        $this->assertFalse(TrainingDealLegSemantics::buyerSecondLegReceivesCrypto($lot));
    }

    public function test_stablecoin_to_fiat_training_legs(): void
    {
        $lot = $this->trainingLot('stablecoin', 'fiat');

        $this->assertFalse(TrainingDealLegSemantics::buyerFirstLegIsBankTransfer($lot));
        $this->assertFalse(TrainingDealLegSemantics::buyerSecondLegReceivesCrypto($lot));
    }

    public function test_non_training_returns_null(): void
    {
        $lot = $this->trainingLot('stablecoin', 'fiat');
        $lot->is_training = false;

        $this->assertNull(TrainingDealLegSemantics::buyerFirstLegIsBankTransfer($lot));
        $this->assertNull(TrainingDealLegSemantics::buyerSecondLegReceivesCrypto($lot));
    }

    private function trainingLot(string $fromType, string $toType): ExchangeLot
    {
        $lot = new ExchangeLot(['is_training' => true]);
        if ($fromType === 'fiat') {
            $lot->from_fiat_currency_id = 1;
            $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        } else {
            $lot->from_crypto_currency_id = 1;
            $lot->setRelation('fromCryptoCurrency', (object) [
                'code' => 'usdt_stellar',
                'is_native' => false,
                'network' => 'stellar',
            ]);
        }
        if ($toType === 'fiat') {
            $lot->to_fiat_currency_id = 2;
            $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        } else {
            $lot->to_crypto_currency_id = 2;
            $lot->setRelation('toCryptoCurrency', (object) [
                'code' => 'usdt_stellar',
                'is_native' => false,
                'network' => 'stellar',
            ]);
        }

        return $lot;
    }
}

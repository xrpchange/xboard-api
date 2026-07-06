<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\CryptoToFiatDealSupport;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
class CryptoToFiatStartDealTierValidationTest extends TestCase
{
    private function xmrToFiatLot(bool $aml): ExchangeLot
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

    public function test_monero_crypto_to_fiat_skips_remaining_volume_tier_gate_even_with_aml(): void
    {
        $lot = $this->xmrToFiatLot(aml: true);

        $this->assertTrue(CryptoToFiatDealSupport::isMoneroCryptoToFiatDeal($lot));
        $this->assertTrue(CryptoToFiatDealSupport::skipsStartDealRemainingVolumeTierCheck($lot));
        $this->assertSame([100.0, 200.0], $lot->configuredBuyAmounts());
        $this->assertSame([100.0], $lot->availableBuyAmountsForRemaining());
    }

    public function test_non_aml_monero_crypto_to_fiat_skips_remaining_volume_tier_gate(): void
    {
        $lot = $this->xmrToFiatLot(aml: false);

        $this->assertTrue(CryptoToFiatDealSupport::skipsStartDealRemainingVolumeTierCheck($lot));
    }

    public function test_aml_usdt_to_fiat_keeps_remaining_volume_tier_gate(): void
    {
        $lot = new ExchangeLot([
            'aml' => true,
            'sell_amount' => 0.8,
            'sold_crypto_amount' => 0.0,
            'rate' => 150.0,
            'buy_amounts' => [100.0, 200.0],
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', null);
        $lot->setRelation('fromFiatCurrency', null);

        $this->assertTrue(CryptoToFiatDealSupport::isCryptoToFiatDeal($lot));
        $this->assertFalse(CryptoToFiatDealSupport::isMoneroCryptoToFiatDeal($lot));
        $this->assertFalse(CryptoToFiatDealSupport::skipsStartDealRemainingVolumeTierCheck($lot));
        $this->assertSame([100.0], $lot->availableBuyAmountsForRemaining());
    }

    public function test_usd_to_xmr_skips_remaining_volume_tier_gate(): void
    {
        $lot = new ExchangeLot([
            'aml' => false,
            'from_currency' => 'USD',
            'to_currency' => 'xmr',
            'sell_amount' => 1000.0,
            'sold_crypto_amount' => 0.0,
            'rate' => 150.0,
            'buy_amounts' => [0.5, 1.0],
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('fromCryptoCurrency', null);

        $this->assertTrue(CryptoToFiatDealSupport::isCryptoToFiatDeal($lot));
        $this->assertTrue(CryptoToFiatDealSupport::isMoneroCryptoToFiatDeal($lot));
        $this->assertTrue(CryptoToFiatDealSupport::skipsStartDealRemainingVolumeTierCheck($lot));
    }

    public function test_fiat_to_usdt_mixed_pair_does_not_skip_remaining_volume_tier_gate(): void
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

        $this->assertTrue(CryptoToFiatDealSupport::isCryptoToFiatDeal($lot));
        $this->assertFalse(CryptoToFiatDealSupport::skipsStartDealRemainingVolumeTierCheck($lot));
    }

    public function test_legacy_monero_crypto_to_fiat_without_from_crypto_fk_skips_remaining_volume_tier_gate(): void
    {
        $lot = new ExchangeLot([
            'aml' => false,
            'from_crypto_currency_id' => null,
            'crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
            'to_crypto_currency_id' => null,
            'sell_amount' => 0.8,
            'sold_crypto_amount' => 0.0,
            'rate' => 150.0,
            'buy_amounts' => [100.0, 200.0],
        ]);
        $lot->setRelation('fromCryptoCurrency', null);
        $lot->setRelation('cryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('fromFiatCurrency', null);

        $this->assertTrue(CryptoToFiatDealSupport::isMoneroCryptoToFiatDeal($lot));
        $this->assertTrue(CryptoToFiatDealSupport::skipsStartDealRemainingVolumeTierCheck($lot));
        $this->assertSame([100.0, 200.0], $lot->configuredBuyAmounts());
    }

    #[Group('spec-LOT-003')]
    public function test_seller_escrow_public_json_for_reservation_release(): void
    {
        $lot = $this->xmrToFiatLot(aml: false);
        $sellerJson = '{"token_hash":"e9626d4e'.str_repeat('a', 56).'","currency":"XMR","wallet_address":"{\"primary\":\"9abc\"}"}';
        $lot->wallet_address = $sellerJson;

        $this->assertSame($sellerJson, CryptoToFiatDealSupport::sellerEscrowPublicJsonForReservationRelease($lot));

        $fiatToCrypto = new ExchangeLot([
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $fiatToCrypto->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $fiatToCrypto->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $fiatToCrypto->setRelation('fromCryptoCurrency', null);
        $fiatToCrypto->setRelation('toFiatCurrency', null);
        $fiatToCrypto->wallet_address = $sellerJson;
        $this->assertNull(CryptoToFiatDealSupport::sellerEscrowPublicJsonForReservationRelease($fiatToCrypto));
    }
}

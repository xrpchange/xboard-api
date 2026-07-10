<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\CryptoToFiatDealSupport;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Seller pre-reservation at send-payment-details: service_fee_only only when buyer pays crypto first
 * on crypto→fiat (from=crypto, to=fiat). Fiat→crypto mixed pairs require full lock (balance checked in Core).
 */
#[Group('spec')]
#[Group('spec-LOT-026')]
class SellerPaymentDetailsReservationModeTest extends TestCase
{
    public function test_usdt_to_usd_is_buyer_crypto_first_leg_crypto_to_fiat(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('fromFiatCurrency', null);
        $lot->setRelation('toCryptoCurrency', null);

        $this->assertTrue(CryptoToFiatDealSupport::isCryptoToFiatDeal($lot));
        $this->assertTrue(CryptoToFiatDealSupport::buyerCryptoFirstLegCryptoToFiat($lot));
        $this->assertFalse(CryptoToFiatDealSupport::buyerFiatFirstLegFiatToCrypto($lot));
    }

    public function test_usd_to_usdt_mixed_pair_seller_sends_crypto_on_second_leg(): void
    {
        $lot = new ExchangeLot([
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('fromCryptoCurrency', null);

        $this->assertTrue(CryptoToFiatDealSupport::isCryptoToFiatDeal($lot));
        $this->assertFalse(CryptoToFiatDealSupport::buyerCryptoFirstLegCryptoToFiat($lot));
        $this->assertTrue(CryptoToFiatDealSupport::buyerFiatFirstLegFiatToCrypto($lot));
        $this->assertTrue(\App\Support\DealSellerSecondLeg::sendsCrypto($lot));
        $this->assertTrue(\App\Support\DealSellerSecondLeg::requiresPrePaymentDetailsReservation($lot));
    }

    public function test_usdt_to_usd_lot_fields_seller_sends_fiat_on_second_leg(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('fromFiatCurrency', null);

        $this->assertTrue(CryptoToFiatDealSupport::buyerCryptoFirstLegCryptoToFiat($lot));
        $this->assertFalse(\App\Support\DealSellerSecondLeg::sendsCrypto($lot));
    }

    public function test_crypto_to_crypto_requires_pre_payment_details_reservation(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('fromFiatCurrency', null);
        $lot->setRelation('toFiatCurrency', null);

        $this->assertTrue(\App\Support\DealSellerSecondLeg::sendsCrypto($lot));
        $this->assertTrue(\App\Support\DealSellerSecondLeg::requiresPrePaymentDetailsReservation($lot));
    }
}

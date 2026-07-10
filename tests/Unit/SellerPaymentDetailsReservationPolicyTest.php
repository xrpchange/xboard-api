<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\CryptoToFiatDealSupport;
use App\Support\SellerPaymentDetailsReservationPolicy;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-026')]
class SellerPaymentDetailsReservationPolicyTest extends TestCase
{
    public function test_xmr_to_usd_bank_payment_details_requires_service_fee_only_reservation(): void
    {
        $lot = new ExchangeLot([
            'crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
            'from_currency' => 'xmr',
            'to_currency' => 'USD',
        ]);
        $lot->setRelation('cryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('fromCryptoCurrency', null);
        $lot->setRelation('fromFiatCurrency', null);

        $this->assertTrue(CryptoToFiatDealSupport::buyerCryptoFirstLegCryptoToFiat($lot));
        $this->assertTrue(SellerPaymentDetailsReservationPolicy::preReservationRequired($lot, 'bank'));
        $this->assertTrue(SellerPaymentDetailsReservationPolicy::serviceFeeOnlyPreReserve($lot));
    }

    public function test_usd_to_usdt_crypto_payment_details_requires_full_reservation(): void
    {
        $lot = new ExchangeLot([
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('fromCryptoCurrency', null);

        $this->assertTrue(SellerPaymentDetailsReservationPolicy::preReservationRequired($lot, 'crypto'));
        $this->assertFalse(SellerPaymentDetailsReservationPolicy::serviceFeeOnlyPreReserve($lot));
    }

    public function test_crypto_to_crypto_bank_payment_details_does_not_require_reservation(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('fromFiatCurrency', null);
        $lot->setRelation('toFiatCurrency', null);

        $this->assertFalse(SellerPaymentDetailsReservationPolicy::preReservationRequired($lot, 'bank'));
    }
}

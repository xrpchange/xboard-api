<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\SellerEscrowReservedAmount;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-010')]
class SellerEscrowReservedAmountTest extends TestCase
{
    public function test_xmr_usd_deal_reserves_crypto_amount_not_fiat_buy_amount(): void
    {
        $lot = new ExchangeLot([
            'buyer_selected_amount' => 3.5,
            'rate' => 3.5,
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true]);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('fromFiatCurrency', null);
        $lot->setRelation('toCryptoCurrency', null);

        $this->assertSame(1.0, SellerEscrowReservedAmount::resolve($lot, 'xmr'));
    }

    public function test_usdt_usd_deal_reserves_usdt_not_usd(): void
    {
        $lot = new ExchangeLot([
            'buyer_selected_amount' => 3.5,
            'rate' => 3.5,
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false]);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('cryptoCurrency', (object) ['code' => 'usdt_tron']);

        $this->assertSame(1.0, SellerEscrowReservedAmount::resolve($lot, 'usdt_tron'));
    }

    #[Group('spec-LOT-013')]
    public function test_deal_crypto_payout_uses_same_units_as_reservation_not_sale_tx_fiat(): void
    {
        $lot = new ExchangeLot([
            'buyer_selected_amount' => 3.5,
            'rate' => 3.5,
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true]);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);

        // Sale transaction.amount stays in buyer to_currency (USD); Core payout must be XMR.
        $this->assertSame(1.0, SellerEscrowReservedAmount::resolve($lot, 'xmr'));
        $this->assertNotSame(3.5, SellerEscrowReservedAmount::resolve($lot, 'xmr'));
    }

    public function test_xmr_usdt_deal_seller_and_buyer_escrow_amounts_differ(): void
    {
        $lot = new ExchangeLot([
            'buyer_selected_amount' => 100.0,
            'rate' => 100.0,
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('fromFiatCurrency', null);
        $lot->setRelation('toFiatCurrency', null);

        $this->assertSame(1.0, SellerEscrowReservedAmount::resolveForSellerEscrowLot($lot));
        $this->assertSame(100.0, SellerEscrowReservedAmount::resolveForBuyerEscrowLot($lot));
    }

    #[Group('spec-LOT-014')]
    public function test_seller_escrow_lot_helper_matches_explicit_xmr_token(): void
    {
        $lot = new ExchangeLot([
            'buyer_selected_amount' => 3.5,
            'rate' => 3.5,
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true]);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);

        $this->assertSame(
            SellerEscrowReservedAmount::resolve($lot, 'xmr'),
            SellerEscrowReservedAmount::resolveForSellerEscrowLot($lot)
        );
    }

    public function test_returns_null_when_token_does_not_match_lot(): void
    {
        $lot = new ExchangeLot([
            'buyer_selected_amount' => 3.5,
            'rate' => 3.5,
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true]);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);

        $this->assertNull(SellerEscrowReservedAmount::resolve($lot, 'btc'));
    }
}

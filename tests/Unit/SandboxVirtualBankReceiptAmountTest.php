<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\SandboxVirtualBankReceiptAmount;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
class SandboxVirtualBankReceiptAmountTest extends TestCase
{
    public function test_fiat_to_crypto_receipt_uses_from_fiat_not_to_crypto_amount(): void
    {
        $lot = new ExchangeLot([
            'buyer_selected_amount' => 100.0,
            'rate' => 100.0,
            'sell_amount' => 1.0,
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false]);
        $lot->setRelation('toFiatCurrency', null);

        $this->assertSame(1.0, SandboxVirtualBankReceiptAmount::resolve($lot));
        $this->assertSame('USD', SandboxVirtualBankReceiptAmount::fiatCurrencyCode($lot));
    }

    public function test_crypto_to_fiat_receipt_uses_to_fiat_amount(): void
    {
        $lot = new ExchangeLot([
            'buyer_selected_amount' => 3.5,
            'rate' => 3.5,
            'sell_amount' => 1.0,
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true]);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('fromFiatCurrency', null);

        $this->assertSame(3.5, SandboxVirtualBankReceiptAmount::resolve($lot));
        $this->assertSame('USD', SandboxVirtualBankReceiptAmount::fiatCurrencyCode($lot));
    }

    public function test_fallback_sell_amount_when_buyer_selected_missing_fiat_from(): void
    {
        $lot = new ExchangeLot([
            'buyer_selected_amount' => null,
            'rate' => 100.0,
            'sell_amount' => 50.0,
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false]);

        $this->assertSame(50.0, SandboxVirtualBankReceiptAmount::resolve($lot));
    }
}

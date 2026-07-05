<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CryptoToFiatDealSupport;
use PHPUnit\Framework\TestCase;

class CryptoToFiatCurrencyPairTest extends TestCase
{
    public function test_crypto_to_fiat_pair_from_crypto_and_to_fiat(): void
    {
        $fromCrypto = (object) ['id' => 1];
        $toFiat = (object) ['id' => 2];

        $this->assertTrue(CryptoToFiatDealSupport::isCryptoToFiatCurrencyPair(null, $fromCrypto, $toFiat, null));
    }

    public function test_fiat_to_crypto_is_mixed_seller_fiat_sale_pair(): void
    {
        $fromFiat = (object) ['id' => 1];
        $toCrypto = (object) ['id' => 2];

        $this->assertTrue(CryptoToFiatDealSupport::isCryptoToFiatCurrencyPair($fromFiat, null, null, $toCrypto));
    }

    public function test_crypto_to_crypto_is_not_mixed_fiat_pair(): void
    {
        $fromCrypto = (object) ['id' => 1];
        $toCrypto = (object) ['id' => 2];

        $this->assertFalse(CryptoToFiatDealSupport::isCryptoToFiatCurrencyPair(null, $fromCrypto, null, $toCrypto));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Support\EscrowTokenOnlyCryptoDealRules;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
class EscrowTokenOnlyCryptoDealRulesTest extends TestCase
{
    public function test_allows_manual_buyer_crypto_tx_for_crypto_to_fiat_lot(): void
    {
        $lot = $this->makeXmrToFiatLot();

        $reject = EscrowTokenOnlyCryptoDealRules::rejectManualCryptoProofIfRequired($lot, [
            't' => 'buyer_crypto_tx',
            'tx_ref' => 'abc123',
        ]);

        $this->assertNull($reject);
    }

    public function test_crypto_to_fiat_buyer_first_leg_does_not_require_server_payout(): void
    {
        $lot = $this->makeXmrToFiatLot();

        $this->assertFalse(EscrowTokenOnlyCryptoDealRules::buyerFirstLegRequiresEscrowTokenServerPayout($lot));
    }

    public function test_usdt_to_usd_does_not_require_buyer_server_payout(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', null);

        $this->assertFalse(EscrowTokenOnlyCryptoDealRules::buyerFirstLegRequiresEscrowTokenServerPayout($lot));
    }

    public function test_crypto_to_fiat_seller_second_leg_does_not_require_escrow_token_only(): void
    {
        $lot = $this->makeXmrToFiatLot();

        $this->assertFalse(EscrowTokenOnlyCryptoDealRules::sellerSecondLegRequiresEscrowTokenOnly($lot));
    }

    public function test_fiat_to_xmr_seller_second_leg_requires_escrow_token_only(): void
    {
        $lot = new ExchangeLot([
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);

        $this->assertTrue(EscrowTokenOnlyCryptoDealRules::sellerSecondLegRequiresEscrowTokenOnly($lot));
    }

    public function test_fiat_to_xmr_rejects_manual_seller_transfer_tx(): void
    {
        $lot = new ExchangeLot([
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
        ]);
        $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);

        $reject = EscrowTokenOnlyCryptoDealRules::rejectManualCryptoProofIfRequired($lot, [
            't' => 'seller_transfer_tx',
            'tx_number' => 'deadbeef',
        ]);

        $this->assertNotNull($reject);
        $this->assertSame('manual_crypto_tx_requires_escrow_payout', $reject['error_code']);
    }

    public function test_xmr_to_usd_allows_manual_seller_transfer_tx_when_not_token_only_second_leg(): void
    {
        $lot = $this->makeXmrToFiatLot();

        $reject = EscrowTokenOnlyCryptoDealRules::rejectManualCryptoProofIfRequired($lot, [
            't' => 'seller_transfer_tx',
            'tx_number' => 'deadbeef',
        ]);

        $this->assertNull($reject);
    }

    public function test_fiat_to_usdt_allows_manual_seller_transfer_tx(): void
    {
        $lot = new ExchangeLot([
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
            'aml' => 1,
        ]);
        $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);

        $this->assertFalse(EscrowTokenOnlyCryptoDealRules::sellerSecondLegRequiresEscrowTokenOnly($lot));

        $reject = EscrowTokenOnlyCryptoDealRules::rejectManualCryptoProofIfRequired($lot, [
            't' => 'seller_transfer_tx',
            'tx_number' => 'deadbeef',
        ]);

        $this->assertNull($reject);
    }

    public function test_aml_crypto_to_crypto_still_requires_buyer_server_payout(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
            'aml' => 1,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xrp', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);

        $this->assertTrue(EscrowTokenOnlyCryptoDealRules::buyerFirstLegRequiresEscrowTokenServerPayout($lot));
    }

    public function test_aml_crypto_to_crypto_buyer_pays_monero_and_seller_escrow_monero_requires_buyer_server_payout(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
            'aml' => 1,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);

        $this->assertTrue(EscrowTokenOnlyCryptoDealRules::buyerFirstLegRequiresEscrowTokenServerPayout($lot));
    }

    public function test_rejects_manual_buyer_crypto_tx_when_buyer_token_is_forced(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
            'aml' => 1,
        ]);
        // Buyer pays Monero first leg (to_currency), seller escrow is XRP (from_currency):
        // forced buyer escrow-token route must block manual tx id proof.
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xrp', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);

        $reject = EscrowTokenOnlyCryptoDealRules::rejectManualCryptoProofIfRequired($lot, [
            't' => 'buyer_crypto_tx',
            'tx_ref' => 'manual-hash',
        ]);

        $this->assertNotNull($reject);
        $this->assertSame('manual_crypto_tx_requires_escrow_payout', $reject['error_code']);
    }

    public function test_rejects_manual_buyer_crypto_tx_when_buyer_pays_crypto_even_without_monero_collision(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
            'aml' => 1,
        ]);
        // Buyer pays crypto first leg, so the server payout route is mandatory.
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);

        $reject = EscrowTokenOnlyCryptoDealRules::rejectManualCryptoProofIfRequired($lot, [
            't' => 'buyer_crypto_tx',
            'tx_ref' => 'manual-hash',
        ]);

        $this->assertNotNull($reject);
        $this->assertSame('manual_crypto_tx_requires_escrow_payout', $reject['error_code']);
    }

    public function test_non_aml_crypto_to_crypto_non_monero_buyer_does_not_require_server_payout(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
            'aml' => 0,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xrp', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);

        $this->assertFalse(EscrowTokenOnlyCryptoDealRules::buyerFirstLegRequiresEscrowTokenServerPayout($lot));
    }

    public function test_non_aml_crypto_to_crypto_buyer_pays_monero_requires_server_payout(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
            'aml' => 0,
        ]);
        // Buyer pays to_currency first leg -> Monero.
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);

        $this->assertTrue(EscrowTokenOnlyCryptoDealRules::buyerFirstLegRequiresEscrowTokenServerPayout($lot));
    }

    public function test_non_aml_crypto_to_crypto_non_monero_buyer_allows_manual_buyer_crypto_tx(): void
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_crypto_currency_id' => 2,
            'aml' => 0,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xrp', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'usdt_tron', 'is_native' => false, 'network' => 'tron']);

        $reject = EscrowTokenOnlyCryptoDealRules::rejectManualCryptoProofIfRequired($lot, [
            't' => 'buyer_crypto_tx',
            'tx_ref' => 'manual-hash',
        ]);

        $this->assertNull($reject);
    }

    public function test_verify_external_payment_for_crypto_to_fiat_buyer_crypto_tx(): void
    {
        $lot = $this->makeXmrToFiatLot();

        $this->assertFalse(
            EscrowTokenOnlyCryptoDealRules::shouldVerifyViaEscrowRecords($lot, [], 'xmr', 'buyer_crypto_tx')
        );
    }

    private function makeXmrToFiatLot(): ExchangeLot
    {
        $lot = new ExchangeLot([
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', null);

        return $lot;
    }
}

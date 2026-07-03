<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Services\Deal\DealCryptoTxCoreVerifier;
use App\Support\EscrowTokenOnlyCryptoDealRules;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
class EscrowTokenOnlyCryptoDealRulesTest extends TestCase
{
    public function test_rejects_manual_seller_transfer_tx_for_xmr_second_leg_lot(): void
    {
        $lot = $this->makeXmrSellerEscrowLot();

        $reject = EscrowTokenOnlyCryptoDealRules::rejectManualCryptoProofIfRequired($lot, [
            't' => 'seller_transfer_tx',
            'tx_number' => 'abc123',
        ]);

        $this->assertNotNull($reject);
        $this->assertSame('manual_crypto_tx_requires_escrow_payout', $reject['error_code']);
    }

    public function test_allows_server_payout_buyer_crypto_tx_tag(): void
    {
        $lot = $this->makeXmrSellerEscrowLot();

        $reject = EscrowTokenOnlyCryptoDealRules::rejectManualCryptoProofIfRequired($lot, [
            't' => 'buyer_crypto_tx',
            'tx_ref' => 'abc123',
            'via_escrow_server' => true,
        ]);

        $this->assertNull($reject);
    }

    public function test_verify_escrow_records_for_xmr_crypto_type(): void
    {
        $lot = $this->makeXmrSellerEscrowLot();

        $this->assertTrue(
            EscrowTokenOnlyCryptoDealRules::shouldVerifyViaEscrowRecords($lot, [], 'xmr', 'seller_transfer_tx')
        );
    }

    public function test_deal_crypto_tx_verifier_accepts_stored_escrow_payout_check(): void
    {
        $lot = $this->makeXmrSellerEscrowLot();
        $verifier = new DealCryptoTxCoreVerifier;
        $result = $verifier->verifyDealTag($lot, [
            't' => 'buyer_crypto_tx',
            'tx_ref' => 'deadbeef',
            'via_escrow_server' => true,
            'core_tx_check' => [
                'source' => 'escrow_token_payout',
                'verified' => true,
                'transaction_id' => 'deadbeef',
            ],
        ]);

        $this->assertTrue($result['verified']);
        $this->assertSame('escrow_token_payout', $result['verification']['source'] ?? null);
    }

    private function makeXmrSellerEscrowLot(): ExchangeLot
    {
        $lot = new ExchangeLot([
            'id' => 9001,
            'from_currency' => 'xmr',
            'to_currency' => 'usd',
            'from_fiat_currency_id' => null,
            'to_fiat_currency_id' => 1,
            'from_crypto_currency_id' => 2,
            'to_crypto_currency_id' => null,
            'rate' => 100,
            'buyer_selected_amount' => 50,
            'aml' => false,
            'is_test' => true,
        ]);

        $fromCrypto = new \App\Models\CryptoCurrency(['code' => 'xmr', 'network' => 'native', 'is_native' => true]);
        $lot->setRelation('fromCryptoCurrency', $fromCrypto);
        $lot->setRelation('toCryptoCurrency', null);
        $lot->setRelation('cryptoCurrency', $fromCrypto);

        return $lot;
    }
}

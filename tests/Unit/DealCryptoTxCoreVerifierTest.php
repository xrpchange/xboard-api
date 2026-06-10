<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Deal\DealCryptoTxCoreVerifier;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
class DealCryptoTxCoreVerifierTest extends TestCase
{
    private DealCryptoTxCoreVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new DealCryptoTxCoreVerifier;
    }

    public function test_normalize_maps_deal_completed_seller_to_seller_transfer_tx(): void
    {
        $normalized = $this->verifier->normalizeCryptoProofTag([
            't' => 'deal_completed_seller',
            'crypto_payout_tx' => 'abc123hash',
        ]);

        $this->assertSame('seller_transfer_tx', $normalized['t']);
        $this->assertSame('abc123hash', $normalized['tx_number']);
    }

    public function test_normalize_maps_operator_mail_reply_with_crypto_payout_tx(): void
    {
        $normalized = $this->verifier->normalizeCryptoProofTag([
            't' => 'operator_mail_reply',
            'crypto_payout_tx' => 'tx_operator_9',
            'body' => 'Compensated',
        ]);

        $this->assertSame('seller_transfer_tx', $normalized['t']);
        $this->assertSame('tx_operator_9', $normalized['tx_number']);
    }

    public function test_tx_ref_from_tag_reads_buyer_crypto_tx_ref(): void
    {
        $txRef = $this->verifier->txRefFromTag([
            't' => 'buyer_crypto_tx',
            'tx_ref' => 'buyer_tx_1',
        ]);

        $this->assertSame('buyer_tx_1', $txRef);
    }

    public function test_tx_ref_from_tag_reads_operator_crypto_payout_after_normalize(): void
    {
        $txRef = $this->verifier->txRefFromTag([
            't' => 'operator_mail_reply',
            'crypto_payout_tx' => 'payout_hash',
        ]);

        $this->assertSame('payout_hash', $txRef);
    }

    public function test_verify_deal_tag_rejects_bank_receipt_proof(): void
    {
        $result = $this->verifier->verifyDealTag(
            new \App\Models\ExchangeLot(['id' => 1]),
            [
                't' => 'seller_transfer_tx',
                'receipt_links' => [['name' => 'receipt.pdf', 'url' => 'https://example.test/r.pdf']],
                'tx_number' => 'should_not_matter',
            ],
        );

        $this->assertFalse($result['verified']);
        $this->assertSame('bank_receipt_not_on_chain', $result['error']['error_code'] ?? null);
    }

    public function test_build_not_verified_message_lists_failed_checks(): void
    {
        $method = new \ReflectionMethod(DealCryptoTxCoreVerifier::class, 'buildNotVerifiedMessage');
        $method->setAccessible(true);
        $message = $method->invoke(null, [
            'is_confirmed' => true,
            'checks' => [
                'address_matches' => false,
                'amount_matches' => false,
            ],
        ]);

        $this->assertStringContainsString('recipient address does not match', $message);
        $this->assertStringContainsString('amount does not match', $message);
    }

    public function test_deployment_environment_follows_lot_is_test(): void
    {
        $sandboxLot = new \App\Models\ExchangeLot(['is_test' => true]);
        $prodLot = new \App\Models\ExchangeLot(['is_test' => false]);

        $this->assertSame('sandbox', DealCryptoTxCoreVerifier::deploymentEnvironmentForLot($sandboxLot));
        $this->assertSame('production', DealCryptoTxCoreVerifier::deploymentEnvironmentForLot($prodLot));
    }

    public function test_blockchain_network_id_for_usdt_tron_sandbox(): void
    {
        $network = DealCryptoTxCoreVerifier::blockchainNetworkId('usdt_tron', 'sandbox');
        $this->assertSame('tron_nile', $network);
    }

    public function test_xmr_skips_expected_address_check(): void
    {
        $method = new \ReflectionMethod(DealCryptoTxCoreVerifier::class, 'supportsExpectedAddressCheck');
        $method->setAccessible(true);
        $this->assertFalse($method->invoke(null, 'xmr'));
        $this->assertTrue($method->invoke(null, 'usdt_tron'));
        $this->assertTrue($method->invoke(null, 'btc'));
    }

    public function test_normalize_core_checks_maps_confirmations_enough(): void
    {
        $method = new \ReflectionMethod(DealCryptoTxCoreVerifier::class, 'normalizeCoreChecks');
        $method->setAccessible(true);
        $normalized = $method->invoke(null, [
            'confirmations_enough' => true,
            'amount_matches' => true,
        ]);

        $this->assertTrue($normalized['confirmations_ok'] ?? false);
        $this->assertTrue($normalized['confirmations_enough'] ?? false);
    }
}

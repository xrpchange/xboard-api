<?php

namespace Tests\Unit;

use App\Support\ApiDeploymentEnvironment;
use App\Support\CryptoTxExplorerUrl;
use App\Support\DealArbitrationTxProofCatalog;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
class DealArbitrationTxProofCatalogTest extends TestCase
{
    public function test_format_digest_lines_includes_tx_and_core_check(): void
    {
        $proofs = [[
            'message_id' => 42,
            'sender_side' => 'buyer',
            'tag_type' => 'buyer_crypto_tx',
            'label' => 'Buyer crypto transfer',
            'tx_id' => 'abc123deadbeef',
            'crypto_type' => 'xmr',
            'explorer_url' => 'https://xmrchain.net/tx/abc123deadbeef',
            'core_tx_check' => [
                'verified' => true,
                'checked_at_utc' => '2026-06-01T12:00:00+00:00',
            ],
            'bank_receipt_only' => false,
        ]];

        $lines = DealArbitrationTxProofCatalog::formatDigestLines($proofs);
        $this->assertStringContainsString('id=42', $lines);
        $this->assertStringContainsString('tx_id=abc123deadbeef', $lines);
        $this->assertStringContainsString('core_tx_check.verified=true', $lines);
    }

    public function test_explorer_url_uses_production_bucket(): void
    {
        config(['xboard.tx_explorer_url_templates' => [
            'production' => ['xmr' => 'https://xmrchain.net/tx/{tx}'],
            'sandbox' => ['xmr' => 'https://stagenet.xmrchain.net/tx/{tx}'],
        ]]);
        $url = CryptoTxExplorerUrl::forTx('xmr', 'deadbeef', 'production');
        $this->assertSame('https://xmrchain.net/tx/deadbeef', $url);
    }

    public function test_explorer_url_uses_sandbox_bucket(): void
    {
        config(['xboard.tx_explorer_url_templates' => [
            'production' => ['btc' => 'https://blockstream.info/tx/{tx}'],
            'sandbox' => ['btc' => 'https://blockstream.info/testnet/tx/{tx}'],
        ]]);
        $url = CryptoTxExplorerUrl::forTx('btc', 'abc', 'sandbox');
        $this->assertSame('https://blockstream.info/testnet/tx/abc', $url);
    }

    public function test_deployment_environment_from_host(): void
    {
        $this->assertSame('sandbox', ApiDeploymentEnvironment::resolveFromHost('sandbox.api.xdexboard.com'));
        $this->assertSame('production', ApiDeploymentEnvironment::resolveFromHost('api.xdexboard.com'));
    }

    public function test_format_digest_lines_includes_buyer_bank_receipt_confirmation(): void
    {
        $proofs = [[
            'message_id' => 11,
            'sender_side' => 'buyer',
            'tag_type' => 'receipt_confirmation',
            'label' => 'Buyer bank payment (receipt proof)',
            'tx_id' => '',
            'crypto_type' => null,
            'explorer_url' => null,
            'core_tx_check' => null,
            'bank_receipt_only' => true,
        ]];

        $lines = DealArbitrationTxProofCatalog::formatDigestLines($proofs);
        $this->assertStringContainsString('receipt_confirmation', $lines);
        $this->assertStringContainsString('buyer bank receipt', $lines);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CryptoCurrency;
use App\Models\ExchangeLot;
use App\Models\Node;
use App\Support\DealEscrowPublicPart;
use App\Support\TrainingDealEscrowTokenFormat;
use Illuminate\Console\Command;

/**
 * Create or refresh sandbox training lots (USDT Stellar crypto→crypto, automated seller).
 *
 * Requires TRAINING_SELLER_ESCROW_PUBLIC_JSON (or wallet JSON in env) on sandbox API host.
 */
class SyncTrainingDealLotsCommand extends Command
{
    protected $signature = 'training:sync-lots {--dry-run : Show actions without writing}';

    protected $description = 'Upsert sandbox training exchange lots for deal learning practice';

    public function handle(): int
    {
        if (! filter_var(config('training_deal.enabled', false), FILTER_VALIDATE_BOOL)) {
            $this->warn('TRAINING_DEAL_ENABLED is false — set it in .env to sync training lots.');

            return self::FAILURE;
        }

        $publicJson = trim((string) config('training_deal.seller_escrow_public_json', ''));
        if ($publicJson === '' || ! str_starts_with($publicJson, '{')) {
            $tokenRaw = trim((string) config('training_deal.seller_escrow_token', ''));
            if ($tokenRaw !== '') {
                $publicJson = TrainingDealEscrowTokenFormat::publicPartJson($tokenRaw) ?? '';
            }
        }
        if ($publicJson === '' || ! str_starts_with($publicJson, '{')) {
            $this->error('TRAINING_SELLER_ESCROW_PUBLIC_JSON or storage/app/training_seller_token.json required.');

            return self::FAILURE;
        }

        $tokenHash = DealEscrowPublicPart::tokenHashFromJson($publicJson);
        if ($tokenHash === null) {
            $this->error('Public JSON missing token_hash.');

            return self::FAILURE;
        }

        $usdt = CryptoCurrency::byCode('usdt_stellar')->first();
        if ($usdt === null) {
            $this->error('Crypto currency usdt_stellar not found — run CurrencySeeder.');

            return self::FAILURE;
        }

        $nodeId = (int) config('training_deal.lot_node_id', 0);
        $node = $nodeId > 0
            ? Node::query()->where('id', $nodeId)->where('is_active', true)->first()
            : Node::query()->where('is_active', true)->orderBy('id')->first();

        if ($node === null) {
            $this->error('No active node found for training lot.');

            return self::FAILURE;
        }

        $sellAmount = (float) config('training_deal.lot_sell_amount', 5);
        $buyAmounts = config('training_deal.lot_buy_amounts', [5]);
        if (! is_array($buyAmounts) || $buyAmounts === []) {
            $buyAmounts = [5.0];
        }
        $buyAmounts = array_values(array_filter(array_map('floatval', $buyAmounts), fn ($v) => $v > 0));
        if ($buyAmounts === []) {
            $buyAmounts = [5.0];
        }

        $attrs = [
            'node_id' => (int) $node->id,
            'from_crypto_currency_id' => $usdt->id,
            'to_crypto_currency_id' => $usdt->id,
            'crypto_currency_id' => $usdt->id,
            'sell_amount' => $sellAmount,
            'buy_amount_min' => min($buyAmounts),
            'buy_amount_max' => max($buyAmounts),
            'buy_amounts' => $buyAmounts,
            'rate' => count($buyAmounts) === 1 && $sellAmount > 0 ? ($buyAmounts[0] / $sellAmount) : 1.0,
            'wallet_address' => $publicJson,
            'wallet_hash' => $tokenHash,
            'is_for_individuals' => true,
            'is_for_legal_entities' => false,
            'is_active' => true,
            'is_test' => true,
            'is_training' => true,
            'test_group' => null,
            'note' => (string) config('training_deal.lot_note', 'Training deal lot'),
            'seller_locale' => 'en',
            'aml' => false,
            'seller_notification_email' => config('training_deal.seller_notification_email'),
            'expires_at' => now('UTC')->addYear(),
        ];

        $existing = ExchangeLot::query()
            ->where('is_training', true)
            ->where('wallet_hash', $tokenHash)
            ->first();

        if ($this->option('dry-run')) {
            $this->info($existing ? "Would update training lot #{$existing->id}" : 'Would create new training lot');
            $this->line(json_encode($attrs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($existing !== null) {
            $existing->update($attrs);
            $this->info("Updated training lot #{$existing->id} on node {$node->domain}");

            return self::SUCCESS;
        }

        $lot = ExchangeLot::create($attrs);
        $this->info("Created training lot #{$lot->id} on node {$node->domain}");

        return self::SUCCESS;
    }
}

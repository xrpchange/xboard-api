<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CryptoCurrency;
use App\Models\ExchangeLot;
use App\Models\FiatCurrency;
use App\Models\Node;
use App\Services\SandboxVirtualBankService;
use App\Support\DealEscrowPublicPart;
use App\Support\TrainingDealEscrowTokenFormat;
use Illuminate\Console\Command;

/**
 * Create or refresh sandbox training lots: USD↔USDT Stellar (fiat↔crypto), automated seller.
 */
class SyncTrainingDealLotsCommand extends Command
{
    protected $signature = 'training:sync-lots {--dry-run : Show actions without writing}';

    protected $description = 'Upsert sandbox training exchange lots (fiat→crypto and crypto→fiat)';

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
        $usd = FiatCurrency::where('code', 'USD')->first();
        if ($usdt === null || $usd === null) {
            $this->error('Required currencies missing (usdt_stellar, USD) — run CurrencySeeder.');

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

        $common = [
            'node_id' => (int) $node->id,
            'crypto_currency_id' => $usdt->id,
            'wallet_address' => $publicJson,
            'wallet_hash' => $tokenHash,
            'is_for_individuals' => true,
            'is_for_legal_entities' => false,
            'is_active' => true,
            'is_test' => true,
            'is_training' => true,
            'test_group' => null,
            'seller_locale' => 'en',
            'aml' => true,
            'seller_notification_email' => config('training_deal.seller_notification_email'),
            'virtual_sandbox_bank_code' => SandboxVirtualBankService::bankCode(),
            'expires_at' => now('UTC')->addYear(),
        ];

        $profiles = [
            [
                'key' => 'fiat_crypto',
                'note' => (string) config('training_deal.lot_note_fiat_crypto', 'Training: USD → USDT (Stellar)'),
                'from_fiat_currency_id' => $usd->id,
                'from_crypto_currency_id' => null,
                'to_fiat_currency_id' => null,
                'to_crypto_currency_id' => $usdt->id,
                'sell_amount' => $sellAmount,
                'buy_amount_min' => min($buyAmounts),
                'buy_amount_max' => max($buyAmounts),
                'buy_amounts' => $buyAmounts,
                'rate' => count($buyAmounts) === 1 && $sellAmount > 0 ? ($buyAmounts[0] / $sellAmount) : 1.0,
            ],
            [
                'key' => 'crypto_fiat',
                'note' => (string) config('training_deal.lot_note_crypto_fiat', 'Training: USDT (Stellar) → USD'),
                'from_fiat_currency_id' => null,
                'from_crypto_currency_id' => $usdt->id,
                'to_fiat_currency_id' => $usd->id,
                'to_crypto_currency_id' => null,
                'sell_amount' => $sellAmount,
                'buy_amount_min' => min($buyAmounts),
                'buy_amount_max' => max($buyAmounts),
                'buy_amounts' => $buyAmounts,
                'rate' => count($buyAmounts) === 1 && $sellAmount > 0 ? ($buyAmounts[0] / $sellAmount) : 1.0,
            ],
        ];

        if ($this->option('dry-run')) {
            foreach ($profiles as $profile) {
                $this->info('Would upsert training lot: '.$profile['key']);
                $this->line(json_encode($common + $profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            return self::SUCCESS;
        }

        foreach ($profiles as $profile) {
            $key = $profile['key'];
            unset($profile['key']);

            $existing = ExchangeLot::query()
                ->where('is_training', true)
                ->where('wallet_hash', $tokenHash)
                ->when($key === 'fiat_crypto', fn ($q) => $q->where('from_fiat_currency_id', $usd->id)->where('to_crypto_currency_id', $usdt->id))
                ->when($key === 'crypto_fiat', fn ($q) => $q->where('from_crypto_currency_id', $usdt->id)->where('to_fiat_currency_id', $usd->id))
                ->first();

            $attrs = array_merge($common, $profile);

            if ($existing !== null) {
                // Practice lots are reused; reset sold volume so search shows them after a completed run.
                $attrs['sold_crypto_amount'] = 0;
                $existing->update($attrs);
                $this->info("Updated training lot #{$existing->id} ({$key}) on node {$node->domain}");
            } else {
                $lot = ExchangeLot::create($attrs);
                $this->info("Created training lot #{$lot->id} ({$key}) on node {$node->domain}");
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ExchangeLot;
use App\Models\CryptoCurrency;
use App\Models\FiatCurrency;
use App\Models\Country;
use App\Models\Bank;
use Carbon\Carbon;

class ExchangeLotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get currencies
        $btc = CryptoCurrency::where('code', 'BTC')->first();
        $eth = CryptoCurrency::where('code', 'ETH')->first();
        $xrp = CryptoCurrency::where('code', 'XRP')->first();
        $usdt = CryptoCurrency::where('code', 'USDT')->first();
        $usdc = CryptoCurrency::where('code', 'USDC')->first();
        
        $usd = FiatCurrency::where('code', 'USD')->first();
        $eur = FiatCurrency::where('code', 'EUR')->first();
        $rub = FiatCurrency::where('code', 'RUB')->first();
        $gbp = FiatCurrency::where('code', 'GBP')->first();
        
        // Get countries and banks
        $russia = Country::where('code', 'RUS')->first();
        $usa = Country::where('code', 'USA')->first();
        $germany = Country::where('code', 'DEU')->first();
        
        $sberbank = Bank::where('swift_code', 'SABRRUMM')->first();
        $chase = Bank::where('swift_code', 'CHASUS33')->first();
        $deutsche = Bank::where('swift_code', 'DEUTDEFF')->first();
        
        if (!$btc || !$eth || !$xrp || !$usd || !$eur || !$rub) {
            $this->command->error('Required currencies not found. Please run CurrencySeeder first.');
            return;
        }
        
        // Clear existing exchange lots (except nodes are preserved)
        ExchangeLot::truncate();
        
        $lots = [];
        
        // ===== CRYPTO -> FIAT =====
        
        // BTC -> USD (Active, for individuals)
        if ($btc && $usd && $usa && $chase) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id, // Collateral token
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'crypto',
                'sell_currency_id' => $btc->id,
                'sell_amount' => 1.5,
                'buy_currency_type' => 'fiat',
                'buy_currency_id' => $usd->id,
                'buy_amount' => 67500.00, // ~$45,000 per BTC
                'country_id' => $usa->id,
                'bank_id' => $chase->id,
                'bank_account' => '****1234',
                'payment_purpose' => 'Bitcoin exchange',
                'is_for_individuals' => true,
                'is_for_legal_entities' => false,
                'is_active' => true,
                'is_test' => false,
                'expires_at' => Carbon::now()->addDays(7),
            ];
        }
        
        // BTC -> EUR (Active, for both)
        if ($btc && $eur && $germany && $deutsche) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'crypto',
                'sell_currency_id' => $btc->id,
                'sell_amount' => 0.5,
                'buy_currency_type' => 'fiat',
                'buy_currency_id' => $eur->id,
                'buy_amount' => 22500.00, // ~€45,000 per BTC
                'country_id' => $germany->id,
                'bank_id' => $deutsche->id,
                'bank_account' => '****5678',
                'payment_purpose' => 'Bitcoin purchase',
                'is_for_individuals' => true,
                'is_for_legal_entities' => true,
                'is_active' => true,
                'is_test' => false,
                'expires_at' => Carbon::now()->addDays(5),
            ];
        }
        
        // ETH -> USD (Active, large amount)
        if ($eth && $usd && $usa && $chase) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'crypto',
                'sell_currency_id' => $eth->id,
                'sell_amount' => 10.0,
                'buy_currency_type' => 'fiat',
                'buy_currency_id' => $usd->id,
                'buy_amount' => 35000.00, // ~$3,500 per ETH
                'country_id' => $usa->id,
                'bank_id' => $chase->id,
                'bank_account' => '****9012',
                'payment_purpose' => 'Ethereum exchange',
                'is_for_individuals' => true,
                'is_for_legal_entities' => false,
                'is_active' => true,
                'is_test' => false,
                'expires_at' => Carbon::now()->addDays(10),
            ];
        }
        
        // XRP -> RUB (Active, for individuals)
        if ($xrp && $rub && $russia && $sberbank) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'crypto',
                'sell_currency_id' => $xrp->id,
                'sell_amount' => 10000.0,
                'buy_currency_type' => 'fiat',
                'buy_currency_id' => $rub->id,
                'buy_amount' => 600000.00, // ~60 RUB per XRP
                'country_id' => $russia->id,
                'bank_id' => $sberbank->id,
                'bank_account' => '****3456',
                'payment_purpose' => 'Ripple exchange',
                'is_for_individuals' => true,
                'is_for_legal_entities' => false,
                'is_active' => true,
                'is_test' => false,
                'expires_at' => Carbon::now()->addDays(3),
            ];
        }
        
        // USDT -> USD (Stablecoin, 1:1)
        if ($usdt && $usd && $usa && $chase) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'crypto',
                'sell_currency_id' => $usdt->id,
                'sell_amount' => 5000.0,
                'buy_currency_type' => 'fiat',
                'buy_currency_id' => $usd->id,
                'buy_amount' => 5000.0, // 1:1 rate
                'country_id' => $usa->id,
                'bank_id' => $chase->id,
                'bank_account' => '****7890',
                'payment_purpose' => 'USDT to USD',
                'is_for_individuals' => true,
                'is_for_legal_entities' => true,
                'is_active' => true,
                'is_test' => false,
                'expires_at' => Carbon::now()->addDays(14),
            ];
        }
        
        // ===== FIAT -> CRYPTO =====
        
        // USD -> BTC
        if ($usd && $btc && $usa && $chase) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'fiat',
                'sell_currency_id' => $usd->id,
                'sell_amount' => 45000.00,
                'buy_currency_type' => 'crypto',
                'buy_currency_id' => $btc->id,
                'buy_amount' => 1.0,
                'country_id' => $usa->id,
                'bank_id' => $chase->id,
                'bank_account' => '****2468',
                'payment_purpose' => 'Bitcoin purchase',
                'is_for_individuals' => true,
                'is_for_legal_entities' => false,
                'is_active' => true,
                'is_test' => false,
                'expires_at' => Carbon::now()->addDays(6),
            ];
        }
        
        // EUR -> ETH
        if ($eur && $eth && $germany && $deutsche) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'fiat',
                'sell_currency_id' => $eur->id,
                'sell_amount' => 3500.00,
                'buy_currency_type' => 'crypto',
                'buy_currency_id' => $eth->id,
                'buy_amount' => 1.0,
                'country_id' => $germany->id,
                'bank_id' => $deutsche->id,
                'bank_account' => '****1357',
                'payment_purpose' => 'Ethereum purchase',
                'is_for_individuals' => true,
                'is_for_legal_entities' => true,
                'is_active' => true,
                'is_test' => false,
                'expires_at' => Carbon::now()->addDays(8),
            ];
        }
        
        // RUB -> XRP
        if ($rub && $xrp && $russia && $sberbank) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'fiat',
                'sell_currency_id' => $rub->id,
                'sell_amount' => 600000.00,
                'buy_currency_type' => 'crypto',
                'buy_currency_id' => $xrp->id,
                'buy_amount' => 10000.0,
                'country_id' => $russia->id,
                'bank_id' => $sberbank->id,
                'bank_account' => '****9876',
                'payment_purpose' => 'Ripple purchase',
                'is_for_individuals' => true,
                'is_for_legal_entities' => false,
                'is_active' => true,
                'is_test' => false,
                'expires_at' => Carbon::now()->addDays(4),
            ];
        }
        
        // ===== CRYPTO -> CRYPTO =====
        
        // BTC -> ETH
        if ($btc && $eth) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'crypto',
                'sell_currency_id' => $btc->id,
                'sell_amount' => 0.1,
                'buy_currency_type' => 'crypto',
                'buy_currency_id' => $eth->id,
                'buy_amount' => 12.85, // ~12.85 ETH per 0.1 BTC
                'country_id' => null,
                'bank_id' => null,
                'bank_account' => null,
                'payment_purpose' => null,
                'is_for_individuals' => true,
                'is_for_legal_entities' => true,
                'is_active' => true,
                'is_test' => false,
                'expires_at' => Carbon::now()->addDays(12),
            ];
        }
        
        // ETH -> USDT
        if ($eth && $usdt) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'crypto',
                'sell_currency_id' => $eth->id,
                'sell_amount' => 5.0,
                'buy_currency_type' => 'crypto',
                'buy_currency_id' => $usdt->id,
                'buy_amount' => 17500.0, // ~$3,500 per ETH
                'country_id' => null,
                'bank_id' => null,
                'bank_account' => null,
                'payment_purpose' => null,
                'is_for_individuals' => true,
                'is_for_legal_entities' => false,
                'is_active' => true,
                'is_test' => false,
                'expires_at' => Carbon::now()->addDays(9),
            ];
        }
        
        // ===== TEST LOTS (Sandbox) =====
        
        // Test: BTC -> USD
        if ($btc && $usd && $usa && $chase) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'crypto',
                'sell_currency_id' => $btc->id,
                'sell_amount' => 0.01,
                'buy_currency_type' => 'fiat',
                'buy_currency_id' => $usd->id,
                'buy_amount' => 450.00,
                'country_id' => $usa->id,
                'bank_id' => $chase->id,
                'bank_account' => 'TEST****',
                'payment_purpose' => 'Test exchange',
                'is_for_individuals' => true,
                'is_for_legal_entities' => true,
                'is_active' => true,
                'is_test' => true,
                'test_group' => 'sandbox',
                'expires_at' => Carbon::now()->addDays(30),
            ];
        }
        
        // Test: XRP -> RUB
        if ($xrp && $rub && $russia && $sberbank) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'crypto',
                'sell_currency_id' => $xrp->id,
                'sell_amount' => 100.0,
                'buy_currency_type' => 'fiat',
                'buy_currency_id' => $rub->id,
                'buy_amount' => 6000.00,
                'country_id' => $russia->id,
                'bank_id' => $sberbank->id,
                'bank_account' => 'TEST****',
                'payment_purpose' => 'Test exchange',
                'is_for_individuals' => true,
                'is_for_legal_entities' => false,
                'is_active' => true,
                'is_test' => true,
                'test_group' => 'sandbox',
                'expires_at' => Carbon::now()->addDays(30),
            ];
        }
        
        // ===== EXPIRED LOTS (for testing) =====
        
        // Expired: BTC -> USD
        if ($btc && $usd && $usa && $chase) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'crypto',
                'sell_currency_id' => $btc->id,
                'sell_amount' => 0.5,
                'buy_currency_type' => 'fiat',
                'buy_currency_id' => $usd->id,
                'buy_amount' => 22500.00,
                'country_id' => $usa->id,
                'bank_id' => $chase->id,
                'bank_account' => '****9999',
                'payment_purpose' => 'Expired lot',
                'is_for_individuals' => true,
                'is_for_legal_entities' => false,
                'is_active' => true,
                'is_test' => false,
                'expires_at' => Carbon::now()->subDays(1), // Expired yesterday
            ];
        }
        
        // ===== INACTIVE LOTS =====
        
        // Inactive: ETH -> USD
        if ($eth && $usd && $usa && $chase) {
            $lots[] = [
                'crypto_currency_id' => $xrp->id,
                'wallet_address' => 'rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3',
                'sell_currency_type' => 'crypto',
                'sell_currency_id' => $eth->id,
                'sell_amount' => 2.0,
                'buy_currency_type' => 'fiat',
                'buy_currency_id' => $usd->id,
                'buy_amount' => 7000.00,
                'country_id' => $usa->id,
                'bank_id' => $chase->id,
                'bank_account' => '****8888',
                'payment_purpose' => 'Inactive lot',
                'is_for_individuals' => true,
                'is_for_legal_entities' => false,
                'is_active' => false, // Inactive
                'is_test' => false,
                'expires_at' => Carbon::now()->addDays(15),
            ];
        }
        
        // Create all lots
        foreach ($lots as $lot) {
            ExchangeLot::create($lot);
        }
        
        $this->command->info('Created ' . count($lots) . ' exchange lots');
    }
}


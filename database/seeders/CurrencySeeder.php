<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\FiatCurrency;
use App\Models\CryptoCurrency;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Fiat currencies
        $fiatCurrencies = [
            ['code' => 'RUB', 'name' => 'Russian Ruble', 'symbol' => '₽'],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$'],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€'],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£'],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥'],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥'],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$'],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$'],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF'],
            ['code' => 'BRL', 'name' => 'Brazilian Real', 'symbol' => 'R$'],
        ];

        foreach ($fiatCurrencies as $currency) {
            FiatCurrency::updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }

        // Cryptocurrencies
        $cryptoCurrencies = [
            ['code' => 'XRP', 'name' => 'Ripple', 'is_stablecoin' => false],
            ['code' => 'BTC', 'name' => 'Bitcoin', 'is_stablecoin' => false],
            ['code' => 'ETH', 'name' => 'Ethereum', 'is_stablecoin' => false],
            ['code' => 'USDT', 'name' => 'Tether', 'is_stablecoin' => true],
            ['code' => 'USDC', 'name' => 'USD Coin', 'is_stablecoin' => true],
            ['code' => 'DAI', 'name' => 'Dai', 'is_stablecoin' => true],
            ['code' => 'BUSD', 'name' => 'Binance USD', 'is_stablecoin' => true],
            ['code' => 'LTC', 'name' => 'Litecoin', 'is_stablecoin' => false],
            ['code' => 'BCH', 'name' => 'Bitcoin Cash', 'is_stablecoin' => false],
            ['code' => 'ADA', 'name' => 'Cardano', 'is_stablecoin' => false],
        ];

        foreach ($cryptoCurrencies as $currency) {
            CryptoCurrency::updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }
} 
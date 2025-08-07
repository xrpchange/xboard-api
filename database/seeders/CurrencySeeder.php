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
        // Фиатные валюты
        $fiatCurrencies = [
            ['code' => 'RUB', 'name' => 'Российский рубль', 'symbol' => '₽'],
            ['code' => 'USD', 'name' => 'Доллар США', 'symbol' => '$'],
            ['code' => 'EUR', 'name' => 'Евро', 'symbol' => '€'],
            ['code' => 'GBP', 'name' => 'Фунт стерлингов', 'symbol' => '£'],
            ['code' => 'CNY', 'name' => 'Китайский юань', 'symbol' => '¥'],
            ['code' => 'JPY', 'name' => 'Японская иена', 'symbol' => '¥'],
            ['code' => 'CAD', 'name' => 'Канадский доллар', 'symbol' => 'C$'],
            ['code' => 'AUD', 'name' => 'Австралийский доллар', 'symbol' => 'A$'],
            ['code' => 'CHF', 'name' => 'Швейцарский франк', 'symbol' => 'CHF'],
            ['code' => 'BRL', 'name' => 'Бразильский реал', 'symbol' => 'R$'],
        ];

        foreach ($fiatCurrencies as $currency) {
            FiatCurrency::updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }

        // Криптовалюты
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
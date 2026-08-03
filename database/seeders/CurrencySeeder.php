<?php

namespace Database\Seeders;

use App\Models\CryptoCurrency;
use App\Models\FiatCurrency;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Fiat: static list. Crypto: from Core API GET /api/info (crypto_structure).
     */
    public function run(): void
    {
        $this->seedFiat();
        $this->seedCryptoFromCore();
    }

    private function seedFiat(): void
    {
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
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹'],
            ['code' => 'KZT', 'name' => 'Kazakhstani Tenge', 'symbol' => '₸'],
            ['code' => 'UAH', 'name' => 'Ukrainian Hryvnia', 'symbol' => '₴'],
            ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R'],
            ['code' => 'MXN', 'name' => 'Mexican Peso', 'symbol' => '$'],
            ['code' => 'SEK', 'name' => 'Swedish Krona', 'symbol' => 'kr'],
            ['code' => 'NOK', 'name' => 'Norwegian Krone', 'symbol' => 'kr'],
            ['code' => 'PLN', 'name' => 'Polish Zloty', 'symbol' => 'zł'],
            ['code' => 'CZK', 'name' => 'Czech Koruna', 'symbol' => 'Kč'],
            ['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺'],
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => '﷼'],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ'],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$'],
            ['code' => 'HKD', 'name' => 'Hong Kong Dollar', 'symbol' => 'HK$'],
            ['code' => 'KRW', 'name' => 'South Korean Won', 'symbol' => '₩'],
            ['code' => 'NGN', 'name' => 'Nigerian Naira', 'symbol' => '₦'],
            ['code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => 'E£'],
        ];

        foreach ($fiatCurrencies as $currency) {
            FiatCurrency::updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }

    /**
     * Fetch crypto list from Core GET /api/info and sync to crypto_currencies.
     * Uses config services.core (sandbox). Set CORE_URL to production URL to seed from production.
     */
    private function seedCryptoFromCore(): void
    {
        $coreUrl = rtrim(config('services.core.url', ''), '/');
        $coreKey = config('services.core.key', '');
        $timeout = config('services.core.timeout', 10);

        if (empty($coreUrl)) {
            $this->command?->warn('CORE_URL not set. Skipping crypto seed from Core.');
            return;
        }

        $request = Http::timeout($timeout);
        if ($coreKey !== '') {
            $request = $request->withHeaders(['X-Core-Key' => $coreKey]);
        }

        $response = $request->get($coreUrl . '/api/info');
        if (!$response->successful()) {
            throw new \RuntimeException('Core GET /api/info failed: ' . $response->status() . ' ' . $response->body());
        }

        $body = $response->json();
        $data = $body['data'] ?? null;
        $cryptoStructure = $data['crypto_structure'] ?? [];
        // Preserve string keys (native, stellar, tron…): (array) on stdClass yields numeric keys
        $networksRaw = $cryptoStructure['networks'] ?? [];
        $networks = is_array($networksRaw) ? $networksRaw : json_decode(json_encode($networksRaw), true);
        $orderRaw = $cryptoStructure['order'] ?? [];
        $order = is_array($orderRaw) ? $orderRaw : json_decode(json_encode($orderRaw), true);

        if (empty($networks)) {
            $this->command?->warn('Core returned empty crypto_structure.networks.');
            return;
        }

        // Process every network that has data (order + any extra keys in networks, so native is never skipped)
        $networkNames = array_unique(array_merge($order, array_keys($networks)));
        foreach ($networkNames as $networkName) {
            $networkName = (string) $networkName;
            if ($networkName === '') {
                continue;
            }
            $cryptos = $networks[$networkName] ?? null;
            if (is_object($cryptos)) {
                $cryptos = (array) $cryptos;
            }
            if (!is_array($cryptos)) {
                continue;
            }
            $isNative = strtolower($networkName) === 'native';
            $apiNetwork = $isNative ? null : $networkName;

            foreach ($cryptos as $code => $info) {
                $info = is_array($info) ? $info : (array) (is_object($info) ? $info : []);
                if ($info === []) {
                    continue;
                }
                $id = isset($info['id']) ? (int) $info['id'] : 0;
                if ($id <= 0) {
                    continue;
                }
                $name = $info['name'] ?? $code;

                CryptoCurrency::updateOrCreate(
                    ['id' => $id],
                    [
                        'code'      => $code,
                        'name'      => $name,
                        'is_native' => $isNative,
                        'network'   => $apiNetwork,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}

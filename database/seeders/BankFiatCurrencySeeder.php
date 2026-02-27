<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\FiatCurrency;
use Illuminate\Database\Seeder;

/**
 * Заполняет связь банк — фиатная валюта: каждому банку добавляется национальная валюта страны банка.
 */
class BankFiatCurrencySeeder extends Seeder
{
    /**
     * ISO 3166-1 alpha-3 (страна) => ISO 4217 (национальная валюта).
     * Только страны, для которых в справочнике fiat_currencies есть валюта.
     */
    private const COUNTRY_TO_CURRENCY = [
        'RUS' => 'RUB',
        'USA' => 'USD',
        'DEU' => 'EUR',
        'FRA' => 'EUR',
        'GBR' => 'GBP',
        'CHN' => 'CNY',
        'JPN' => 'JPY',
        'CAN' => 'CAD',
        'AUS' => 'AUD',
        'BRA' => 'BRL',
        'ITA' => 'EUR',
        'ESP' => 'EUR',
        'NLD' => 'EUR',
        'CHE' => 'CHF',
        'IND' => 'INR',
        'KAZ' => 'KZT',
        'UKR' => 'UAH',
        'ZAF' => 'ZAR',
        'MEX' => 'MXN',
        'SWE' => 'SEK',
        'NOR' => 'NOK',
        'POL' => 'PLN',
        'TUR' => 'TRY',
        'SAU' => 'SAR',
        'ARE' => 'AED',
        'SGP' => 'SGD',
        'HKG' => 'HKD',
        'KOR' => 'KRW',
        'NGA' => 'NGN',
        'EGY' => 'EGP',
    ];

    public function run(): void
    {
        $currenciesByCode = FiatCurrency::all()->keyBy('code');

        Bank::with('country')->chunk(100, function ($banks) use ($currenciesByCode) {
            foreach ($banks as $bank) {
                $countryCode = $bank->country?->code;
                if ($countryCode === null) {
                    continue;
                }
                $currencyCode = self::COUNTRY_TO_CURRENCY[$countryCode] ?? null;
                if ($currencyCode === null) {
                    continue;
                }
                $currency = $currenciesByCode->get($currencyCode);
                if ($currency === null) {
                    continue;
                }
                $bank->fiatCurrencies()->syncWithoutDetaching([$currency->id]);
            }
        });
    }
}

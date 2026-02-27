<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Bank;
use App\Models\Country;

class BankSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get countries
        $russia = Country::where('code', 'RUS')->first();
        $usa = Country::where('code', 'USA')->first();
        $germany = Country::where('code', 'DEU')->first();

        if ($russia) {
            $russianBanks = [
                ['name' => 'Sberbank of Russia', 'swift_code' => 'SABRRUMM', 'supports_individuals' => true, 'supports_legal_entities' => true, 'allowed_domain' => 'sberbank.ru'],
                ['name' => 'VTB Bank', 'swift_code' => 'VTBRRUMM', 'supports_individuals' => true, 'supports_legal_entities' => true, 'allowed_domain' => 'vtb.ru'],
                ['name' => 'Gazprombank', 'swift_code' => 'GAZPRUMM', 'supports_individuals' => true, 'supports_legal_entities' => true, 'allowed_domain' => 'gazprombank.ru'],
                ['name' => 'Rosselkhozbank', 'swift_code' => 'RUAGRUMM', 'supports_individuals' => true, 'supports_legal_entities' => true, 'allowed_domain' => null],
                ['name' => 'Alfa-Bank', 'swift_code' => 'ALFARUMM', 'supports_individuals' => true, 'supports_legal_entities' => true, 'allowed_domain' => 'alfabank.ru'],
                ['name' => 'Tinkoff Bank', 'swift_code' => 'TICSRUMM', 'supports_individuals' => true, 'supports_legal_entities' => true, 'allowed_domain' => 'tinkoff.ru'],
                ['name' => 'Raiffeisen Bank', 'swift_code' => 'RZBMRUMM', 'supports_individuals' => true, 'supports_legal_entities' => true, 'allowed_domain' => 'raiffeisen.ru'],
                ['name' => 'Rosbank', 'swift_code' => 'ROSRRUMM', 'supports_individuals' => true, 'supports_legal_entities' => true, 'allowed_domain' => 'rosbank.ru'],
                ['name' => 'OTP Bank', 'swift_code' => 'OTPVRUMM', 'supports_individuals' => true, 'supports_legal_entities' => true, 'allowed_domain' => 'otpbank.ru'],
                ['name' => 'Uralsib', 'swift_code' => 'URTLRUMM', 'supports_individuals' => true, 'supports_legal_entities' => true, 'allowed_domain' => 'uralsib.ru'],
            ];

            foreach ($russianBanks as $bank) {
                Bank::updateOrCreate(
                    ['swift_code' => $bank['swift_code']],
                    array_merge($bank, ['country_id' => $russia->id])
                );
            }
        }

        if ($usa) {
            $usBanks = [
                ['name' => 'JPMorgan Chase', 'swift_code' => 'CHASUS33', 'supports_individuals' => true, 'supports_legal_entities' => true],
                ['name' => 'Bank of America', 'swift_code' => 'BOFAUS3N', 'supports_individuals' => true, 'supports_legal_entities' => true],
                ['name' => 'Wells Fargo', 'swift_code' => 'WFBIUS6S', 'supports_individuals' => true, 'supports_legal_entities' => true],
            ];

            foreach ($usBanks as $bank) {
                Bank::updateOrCreate(
                    ['swift_code' => $bank['swift_code']],
                    array_merge($bank, ['country_id' => $usa->id])
                );
            }
        }

        if ($germany) {
            $germanBanks = [
                ['name' => 'Deutsche Bank', 'swift_code' => 'DEUTDEFF', 'supports_individuals' => true, 'supports_legal_entities' => true],
                ['name' => 'Commerzbank', 'swift_code' => 'COBADEFF', 'supports_individuals' => true, 'supports_legal_entities' => true],
                ['name' => 'Bayerische Landesbank', 'swift_code' => 'BYLADEMM', 'supports_individuals' => false, 'supports_legal_entities' => true],
            ];

            foreach ($germanBanks as $bank) {
                Bank::updateOrCreate(
                    ['swift_code' => $bank['swift_code']],
                    array_merge($bank, ['country_id' => $germany->id])
                );
            }
        }
    }
} 
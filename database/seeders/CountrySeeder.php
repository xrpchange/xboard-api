<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Country;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            ['name' => 'Russia', 'code' => 'RUS', 'phone_code' => '+7'],
            ['name' => 'United States', 'code' => 'USA', 'phone_code' => '+1'],
            ['name' => 'Germany', 'code' => 'DEU', 'phone_code' => '+49'],
            ['name' => 'France', 'code' => 'FRA', 'phone_code' => '+33'],
            ['name' => 'United Kingdom', 'code' => 'GBR', 'phone_code' => '+44'],
            ['name' => 'China', 'code' => 'CHN', 'phone_code' => '+86'],
            ['name' => 'Japan', 'code' => 'JPN', 'phone_code' => '+81'],
            ['name' => 'Canada', 'code' => 'CAN', 'phone_code' => '+1'],
            ['name' => 'Australia', 'code' => 'AUS', 'phone_code' => '+61'],
            ['name' => 'Brazil', 'code' => 'BRA', 'phone_code' => '+55'],
            ['name' => 'India', 'code' => 'IND', 'phone_code' => '+91'],
            ['name' => 'Italy', 'code' => 'ITA', 'phone_code' => '+39'],
            ['name' => 'Spain', 'code' => 'ESP', 'phone_code' => '+34'],
            ['name' => 'Netherlands', 'code' => 'NLD', 'phone_code' => '+31'],
            ['name' => 'Switzerland', 'code' => 'CHE', 'phone_code' => '+41'],
            ['name' => 'Kazakhstan', 'code' => 'KAZ', 'phone_code' => '+7'],
            ['name' => 'Ukraine', 'code' => 'UKR', 'phone_code' => '+380'],
            ['name' => 'South Africa', 'code' => 'ZAF', 'phone_code' => '+27'],
            ['name' => 'Mexico', 'code' => 'MEX', 'phone_code' => '+52'],
            ['name' => 'Sweden', 'code' => 'SWE', 'phone_code' => '+46'],
            ['name' => 'Norway', 'code' => 'NOR', 'phone_code' => '+47'],
            ['name' => 'Poland', 'code' => 'POL', 'phone_code' => '+48'],
            ['name' => 'Turkey', 'code' => 'TUR', 'phone_code' => '+90'],
            ['name' => 'Saudi Arabia', 'code' => 'SAU', 'phone_code' => '+966'],
            ['name' => 'United Arab Emirates', 'code' => 'ARE', 'phone_code' => '+971'],
            ['name' => 'Singapore', 'code' => 'SGP', 'phone_code' => '+65'],
            ['name' => 'Hong Kong', 'code' => 'HKG', 'phone_code' => '+852'],
            ['name' => 'South Korea', 'code' => 'KOR', 'phone_code' => '+82'],
            ['name' => 'Nigeria', 'code' => 'NGA', 'phone_code' => '+234'],
            ['name' => 'Egypt', 'code' => 'EGY', 'phone_code' => '+20'],
        ];

        foreach ($countries as $country) {
            Country::updateOrCreate(
                ['code' => $country['code']],
                $country
            );
        }
    }
} 
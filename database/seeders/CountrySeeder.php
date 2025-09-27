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
        ];

        foreach ($countries as $country) {
            Country::updateOrCreate(
                ['code' => $country['code']],
                $country
            );
        }
    }
} 
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
            ['name' => 'Россия', 'code' => 'RUS', 'phone_code' => '+7'],
            ['name' => 'США', 'code' => 'USA', 'phone_code' => '+1'],
            ['name' => 'Германия', 'code' => 'DEU', 'phone_code' => '+49'],
            ['name' => 'Франция', 'code' => 'FRA', 'phone_code' => '+33'],
            ['name' => 'Великобритания', 'code' => 'GBR', 'phone_code' => '+44'],
            ['name' => 'Китай', 'code' => 'CHN', 'phone_code' => '+86'],
            ['name' => 'Япония', 'code' => 'JPN', 'phone_code' => '+81'],
            ['name' => 'Канада', 'code' => 'CAN', 'phone_code' => '+1'],
            ['name' => 'Австралия', 'code' => 'AUS', 'phone_code' => '+61'],
            ['name' => 'Бразилия', 'code' => 'BRA', 'phone_code' => '+55'],
            ['name' => 'Индия', 'code' => 'IND', 'phone_code' => '+91'],
            ['name' => 'Италия', 'code' => 'ITA', 'phone_code' => '+39'],
            ['name' => 'Испания', 'code' => 'ESP', 'phone_code' => '+34'],
            ['name' => 'Нидерланды', 'code' => 'NLD', 'phone_code' => '+31'],
            ['name' => 'Швейцария', 'code' => 'CHE', 'phone_code' => '+41'],
        ];

        foreach ($countries as $country) {
            Country::updateOrCreate(
                ['code' => $country['code']],
                $country
            );
        }
    }
} 
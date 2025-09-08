<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            ['name' => 'Colombia', 'iso_code' => 'COL', ],
            ['name' => 'Argentina', 'iso_code' => 'ARG'],
            ['name' => 'Brazil', 'iso_code' => 'BRA'],
            ['name' => 'Chile', 'iso_code' => 'CHL'],
            ['name' => 'Mexico', 'iso_code' => 'MEX'],
            ['name' => 'Peru', 'iso_code' => 'PER'],
            ['name' => 'Venezuela', 'iso_code' => 'VEN'],
            ['name' => 'Ecuador', 'iso_code' => 'ECU'],
            ['name' => 'Bolivia', 'iso_code' => 'BOL'],
            ['name' => 'Paraguay', 'iso_code' => 'PRY'],
            ['name' => 'Uruguay', 'iso_code' => 'URY'],
            ['name' => 'France', 'iso_code' => 'FRA'],
            ['name' => 'Germany', 'iso_code' => 'DEU'],
            ['name' => 'United States', 'iso_code' => 'USA'],
            ['name' => 'Canada', 'iso_code' => 'CAN'],
            ['name' => 'Italy', 'iso_code' => 'ITA'],
            ['name' => 'Spain', 'iso_code' => 'ESP'],
            ['name' => 'Portugal', 'iso_code' => 'PRT'],
            ['name' => 'United Kingdom', 'iso_code' => 'GBR'],
            ['name' => 'Australia', 'iso_code' => 'AUS'],
            ['name' => 'Japan', 'iso_code' => 'JPN'],
            ['name' => 'South Korea', 'iso_code' => 'KOR'],
            ['name' => 'China', 'iso_code' => 'CHN'],
            ['name' => 'India', 'iso_code' => 'IND'],
            ['name' => 'South Africa', 'iso_code' => 'ZAF'],
            ['name' => 'Russia', 'iso_code' => 'RUS'],
            ['name' => 'New Zealand', 'iso_code' => 'NZL'],
        ];

        foreach ($countries as $country) {
            DB::table('countries')->insert([
                'name' => $country['name'],
                'iso_code' => $country['iso_code'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

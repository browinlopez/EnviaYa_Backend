<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DeliveryDistanceRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \Illuminate\Support\Facades\DB::table('delivery_distance_rates')->insert([
            ['min_distance_meters' => 0, 'max_distance_meters' => 2000, 'price_cop' => 2000, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['min_distance_meters' => 2001, 'max_distance_meters' => 5000, 'price_cop' => 4000, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['min_distance_meters' => 5001, 'max_distance_meters' => 10000, 'price_cop' => 7000, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}

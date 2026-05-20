<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentGatewaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('payment_gateways')->updateOrInsert(
            ['name' => 'bold'],
            [
                'class' => 'BoldService',
                'state' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AliasSeeder::class,
            RolesAndPermissionsSeeder::class,
            TenderoPermissionsSeeder::class,
            MunicipalitySeeder::class,
            CategoryBusinessSeeder::class,
            CategorySeeder::class,
            PaymentSeeder::class,
            PaymentGatewaySeeder::class,
            TypeDocumentIdentificationSeeder::class,
            DomiciliarioPermissionsSeeder::class,
            CompradorPermissionsSeeder::class,
            UsersSeeder::class,
            TypeOrganizationSeeder::class,
            DeliveryDistanceRateSeeder::class,
        ]);
    }
}

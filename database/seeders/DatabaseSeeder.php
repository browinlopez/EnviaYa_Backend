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
            CountrySeeder::class,
            DepartmentSeeder::class,
            MunicipalitySeeder::class,
            CategoryBusinessSeeder::class,
            CategorySeeder::class,
            PaymentSeeder::class,
            DomiciliarioPermissionsSeeder::class,
            CompradorPermissionsSeeder::class
        ]);
        /* User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]); */
    }
}

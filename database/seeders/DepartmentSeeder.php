<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            ['name' => 'Amazonas', 'country_id' => 1],
            ['name' => 'Antioquia', 'country_id' => 1],
            ['name' => 'Arauca', 'country_id' => 1],
            ['name' => 'Atlántico', 'country_id' => 1],
            ['name' => 'Bolívar', 'country_id' => 1],
            ['name' => 'Boyacá', 'country_id' => 1],
            ['name' => 'Caldas', 'country_id' => 1],
            ['name' => 'Caquetá', 'country_id' => 1],
            ['name' => 'Casanare', 'country_id' => 1],
            ['name' => 'Cauca', 'country_id' => 1],
            ['name' => 'Cesar', 'country_id' => 1],
            ['name' => 'Chocó', 'country_id' => 1],
            ['name' => 'Córdoba', 'country_id' => 1],
            ['name' => 'Cundinamarca', 'country_id' => 1],
            ['name' => 'Guaviare', 'country_id' => 1],
            ['name' => 'Guainía', 'country_id' => 1],
            ['name' => 'Huila', 'country_id' => 1],
            ['name' => 'La Guajira', 'country_id' => 1],
            ['name' => 'Magdalena', 'country_id' => 1],
            ['name' => 'Meta', 'country_id' => 1],
            ['name' => 'Nariño', 'country_id' => 1],
            ['name' => 'Norte de Santander', 'country_id' => 1],
            ['name' => 'Putumayo', 'country_id' => 1],
            ['name' => 'Quindío', 'country_id' => 1],
            ['name' => 'Risaralda', 'country_id' => 1],
            ['name' => 'San Andrés y Providencia', 'country_id' => 1],
            ['name' => 'Santander', 'country_id' => 1],
            ['name' => 'Sucre', 'country_id' => 1],
            ['name' => 'Tolima', 'country_id' => 1],
            ['name' => 'Valle del Cauca', 'country_id' => 1],
            ['name' => 'Vaupés', 'country_id' => 1],
            ['name' => 'Vichada', 'country_id' => 1],
        ];

        foreach ($departments as $department) {
            DB::table('departments')->insert([
                'name' => $department['name'],
                'country_id' => $department['country_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

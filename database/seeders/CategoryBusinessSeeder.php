<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoryBusinessSeeder extends Seeder
{
    public function run(): void
    {
        // Ids EXPLÍCITOS: business.type y category.business_category_id
        // referencian estos números (1 Tienda, 2 Farmacia, 3 Restaurante,
        // 4 Repuestos) — no pueden depender del orden del autoincremental.
        $types = [
            1 => [
                'name' => 'Tienda',
                'description' => 'Tienda de barrio negocio popular para toda la gente',
                'image' => 'https://cdn.pixabay.com/photo/2019/03/13/11/07/supermarket-4052658_1280.jpg',
            ],
            2 => [
                'name' => 'Farmacia',
                'description' => 'Farmacias de barrio',
                'image' => 'https://cdn.pixabay.com/photo/2023/09/20/07/37/doctor-8264060_1280.jpg',
            ],
            3 => [
                'name' => 'Restaurante',
                'description' => 'Restaurantes de comida rápida y del día a día',
                'image' => 'https://cdn.pixabay.com/photo/2015/02/23/21/10/restaurant-646687_1280.jpg',
            ],
            4 => [
                'name' => 'Tienda de repuesto',
                'description' => 'Tienda especializada en repuestos y accesorios',
                'image' => 'https://cdn.pixabay.com/photo/2015/10/07/04/03/automotive-975637_960_720.jpg',
            ],
        ];

        foreach ($types as $id => $data) {
            DB::table('category_business')->updateOrInsert(
                ['id' => $id],
                $data + ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}

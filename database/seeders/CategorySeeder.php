<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run()
    {
        DB::table('category')->insert([
            // 🛒 Categorías de TIENDA
            [
                'name' => 'Abarrotes',
                'description' => 'Productos básicos de almacén y mercado seco',
                'state' => 1,
                'business_category_id' => 1, // TIENDA
            ],
            [
                'name' => 'Bebidas',
                'description' => 'Bebidas gaseosas, jugos, energéticas',
                'state' => 1,
                'business_category_id' => 1,
            ],
            [
                'name' => 'Lácteos y Huevos',
                'description' => 'Productos refrigerados, leche, quesos, huevos',
                'state' => 1,
                'business_category_id' => 1,
            ],
            [
                'name' => 'Snacks',
                'description' => 'Galletas, papas fritas, dulces, confitería',
                'state' => 1,
                'business_category_id' => 1,
            ],
            [
                'name' => 'Aseo y Limpieza',
                'description' => 'Productos de limpieza para hogar y aseo personal',
                'state' => 1,
                'business_category_id' => 1,
            ],

            // 🍽️ Categorías de RESTAURANTE
            [
                'name' => 'Comidas Rápidas',
                'description' => 'Hamburguesas, perros calientes, salchipapas',
                'state' => 1,
                'business_category_id' => 2, // RESTAURANTE
            ],
            [
                'name' => 'Almuerzos',
                'description' => 'Platos del día, corrientazos, menús ejecutivos',
                'state' => 1,
                'business_category_id' => 2,
            ],
            [
                'name' => 'Bebidas y Jugos',
                'description' => 'Jugos naturales, bebidas frías, café',
                'state' => 1,
                'business_category_id' => 2,
            ],
            [
                'name' => 'Postres',
                'description' => 'Tortas, helados, postres fríos',
                'state' => 1,
                'business_category_id' => 2,
            ],
            [
                'name' => 'Adicionales',
                'description' => 'Adiciones como papas, arroz, ensaladas',
                'state' => 1,
                'business_category_id' => 2,
            ],
        ]);
    }
}

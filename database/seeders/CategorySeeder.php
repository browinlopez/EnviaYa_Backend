<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run()
    {
        // business_category_id referencia el TIPO de negocio:
        // 1 Tienda · 2 Farmacia · 3 Restaurante · 4 Repuestos.
        // (Antes las categorías de restaurante apuntaban al 2, que es
        // Farmacia, y Medicina estaba en Tienda.)
        $categories = [
            // Tienda (1)
            ['name' => 'Abarrotes', 'description' => 'Productos básicos de almacén y mercado seco', 'business_category_id' => 1],
            ['name' => 'Bebidas', 'description' => 'Bebidas gaseosas, jugos, energéticas', 'business_category_id' => 1],
            ['name' => 'Lácteos y Huevos', 'description' => 'Productos refrigerados, leche, quesos, huevos', 'business_category_id' => 1],
            ['name' => 'Snacks', 'description' => 'Galletas, papas fritas, dulces, confitería', 'business_category_id' => 1],
            ['name' => 'Aseo y Limpieza', 'description' => 'Productos de limpieza para hogar y aseo personal', 'business_category_id' => 1],

            // Farmacia (2)
            ['name' => 'Medicina', 'description' => 'Medicamentos de venta libre y productos farmacéuticos', 'business_category_id' => 2],
            ['name' => 'Cuidado Personal', 'description' => 'Higiene, dermocosmética y cuidado de la salud', 'business_category_id' => 2],

            // Restaurante (3)
            ['name' => 'Comidas Rápidas', 'description' => 'Hamburguesas, perros calientes, salchipapas', 'business_category_id' => 3],
            ['name' => 'Almuerzos', 'description' => 'Platos del día, corrientazos, menús ejecutivos', 'business_category_id' => 3],
            ['name' => 'Bebidas y Jugos', 'description' => 'Jugos naturales, bebidas frías, café', 'business_category_id' => 3],
            ['name' => 'Postres', 'description' => 'Tortas, helados, postres fríos', 'business_category_id' => 3],
            ['name' => 'Adicionales', 'description' => 'Adiciones como papas, arroz, ensaladas', 'business_category_id' => 3],

            // Repuestos (4)
            ['name' => 'Repuestos', 'description' => 'Repuestos para vehículos y motos', 'business_category_id' => 4],
            ['name' => 'Accesorios', 'description' => 'Accesorios y complementos para vehículos', 'business_category_id' => 4],
        ];

        foreach ($categories as $category) {
            DB::table('category')->updateOrInsert(
                ['name' => $category['name']],
                $category + ['state' => 1],
            );
        }
    }
}

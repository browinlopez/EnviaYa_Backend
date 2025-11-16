<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Collection;
use App\Models\Product\Product;
use App\Models\Product\Category;
use App\Models\Business;
use App\Models\Product\ProductBusiness;

class ProductsImport implements ToCollection
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            // Saltar encabezado
            if (strtolower($row[0]) === 'nombre') {
                continue;
            }

            // Buscar por ID
            $category = Category::find($row[2]);
            $business = Business::find($row[3]);

            if (!$category || !$business) {
                dump("⚠️ Categoría o negocio NO encontrado:", $row);
                continue;
            }

            // Crear producto (tabla products)
            $product = Product::create([
                'name'        => $row[0],
                'description' => $row[1],
                'category_id' => $category->category_id,
                'state'       => $row[6] ?? 1,
            ]);

            // Relación en products_business
            ProductBusiness::create([
                'products_id' => $product->products_id,   // PK correcta
                'busines_id'  => $business->busines_id,  // PK correcta
                'price'       => $row[4],                // precio viene del excel
                'amount'      => $row[5],                // stock viene del excel
                'qualification' => 0,                    // default
            ]);
        }
    }
}

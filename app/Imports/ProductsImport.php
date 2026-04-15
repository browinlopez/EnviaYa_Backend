<?php

namespace App\Imports;

use App\Models\Product\Product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

class ProductsImport implements ToCollection, WithCalculatedFormulas
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            // saltar headers
            if (!isset($row[0]) || $row[0] === 'ID') {
                continue;
            }

            try {

                // 🔥 normalizar category_id
                $categoryId = $row[3];

                if (!is_numeric($categoryId)) {
                    $categoryId = null;
                } else {
                    $categoryId = (int) $categoryId;
                }

                $product = Product::create([
                    'name'        => $row[1] ?? null,
                    'description' => $row[6] ?? null,
                    'category_id' => $categoryId,
                    'state'       => isset($row[7]) && is_numeric($row[7]) ? (int) $row[7] : 1,
                ]);

                dump("✅ Producto creado:", $product->toArray());

            } catch (\Throwable $e) {

                dump("❌ ERROR FILA:");
                dump($row);
                dump($e->getMessage());
            }
        }
    }
}
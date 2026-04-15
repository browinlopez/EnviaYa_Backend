<?php

namespace App\Imports;

use App\Models\Business;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class ProductBusinessImport implements ToCollection
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            // saltar header
            if (!isset($row[0]) || $row[0] === 'ID Producto') {
                continue;
            }

            $productId = $row[0];
            $businessId = $row[1];

            // validar existencia producto
            $product = Product::find($productId);

            if (!$product) {
                dump("❌ Producto no existe: " . $productId);
                continue;
            }

            // validar existencia negocio
            $business = Business::find($businessId);

            if (!$business) {
                dump("❌ Business no existe: " . $businessId);
                continue;
            }

            // evitar duplicados (opcional pero recomendado)
            $exists = ProductBusiness::where('products_id', $productId)
                ->where('busines_id', $businessId)
                ->exists();

            if ($exists) {
                dump("⚠️ Ya existe relación: P={$productId} B={$businessId}");
                continue;
            }

            ProductBusiness::create([
                'products_id' => $productId,
                'busines_id'  => $businessId,
                'price'       => $row[2] ?? 0,
                'amount'      => $row[3] ?? 0,
                'qualification' => 0,
            ]);

            dump("✅ Relación creada P={$productId} B={$businessId}");
        }
    }
}
<?php

namespace App\Imports;

use App\Models\Business;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Siembra la relacion producto-negocio desde un Excel.
 *
 * Lo usa `database/seeders/ProductFromExcelSeeder.php`, o sea que corre por
 * consola y no en una peticion. La carga de catalogo que usa el tendero desde
 * la aplicacion es otra cosa: `ImportadorDeCatalogo`, que pide el codigo del
 * producto y no su `products_id` numerico.
 *
 * ESTO LLEVABA CUATRO `dump()`, y los cuatro se cambiaron por `Log::info()`.
 * No era un riesgo de produccion —un seeder no corre en una peticion— pero
 * `dump()` esta en la lista de prohibiciones de AGENTS.md por un motivo que
 * aqui aplica a medias y que vale la pena no probar: si alguien llama esta
 * clase desde una ruta, `dump()` escribe su salida ENCIMA de la respuesta y
 * el JSON deja de ser JSON. Con `Log::info()` el mensaje se conserva, se
 * puede leer despues de que la siembra acabe, y no depende de que alguien
 * estuviera mirando la terminal en ese momento.
 */
class ProductBusinessImport implements ToCollection
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            // saltar header
            if (! isset($row[0]) || $row[0] === 'ID Producto') {
                continue;
            }

            $productId = $row[0];
            $businessId = $row[1];

            // validar existencia producto
            $product = Product::find($productId);

            if (! $product) {
                Log::info('❌ Producto no existe: '.$productId);

                continue;
            }

            // validar existencia negocio
            $business = Business::find($businessId);

            if (! $business) {
                Log::info('❌ Business no existe: '.$businessId);

                continue;
            }

            // evitar duplicados (opcional pero recomendado)
            $exists = ProductBusiness::where('products_id', $productId)
                ->where('busines_id', $businessId)
                ->exists();

            if ($exists) {
                Log::info("⚠️ Ya existe relación: P={$productId} B={$businessId}");

                continue;
            }

            ProductBusiness::create([
                'products_id' => $productId,
                'busines_id' => $businessId,
                'price' => $row[2] ?? 0,
                'amount' => $row[3] ?? 0,
                'qualification' => 0,
            ]);

            Log::info("✅ Relación creada P={$productId} B={$businessId}");
        }
    }
}

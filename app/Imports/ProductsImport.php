<?php

namespace App\Imports;

use App\Models\Product\Category;
use App\Models\Product\Product;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductsImport implements ToModel, WithChunkReading, WithHeadingRow
{
    protected static $categories = null;

    public function model(array $row)
    {
        if (!$row['nombre_producto']) {
            return null;
        }

        // 🔥 Cargar categorías una sola vez
        if (self::$categories === null) {
            self::$categories = Category::pluck('category_id', 'name')
                ->mapWithKeys(fn($id, $name) => [trim(strtolower($name)) => $id])
                ->toArray();
        }

        // 🔍 Buscar categoría por nombre
        $categoryName = strtolower(trim($row['categoria'] ?? ''));
        $categoryId = self::$categories[$categoryName] ?? null;

        // 🔍 Imagen
        $image = $row['imagen'] ?? null;
        if (!filter_var($image, FILTER_VALIDATE_URL)) {
            $image = null;
        }

        // 🔥 DATA FINAL QUE SE INSERTARÍA
    /*     $data = [
            'name'        => $row['nombre_producto'],
            'description' => $row['descripcion'] ?? null,
            'category_id' => $categoryId,
            'image'       => $image,
            'state'       => isset($row['estado']) && is_numeric($row['estado'])
                ? (int) $row['estado']
                : 1,
        ];

        // 🔥 DEBUG (ver uno y cortar ejecución)
        dd($data); */

        return new Product([
            'name'        => $row['nombre_producto'],
            'description' => $row['descripcion'] ?? null,
            'category_id' => $categoryId,
            'image'       => $image,
            'state'       => isset($row['estado']) && is_numeric($row['estado'])
                ? (int) $row['estado']
                : 1,
        ]);
    }

    public function chunkSize(): int
    {
        return 200;
    }
}

<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Las filas de un archivo de catálogo: SOLO LA PRIMERA HOJA.
 *
 * Con `ToCollection` a secas, maatwebsite recorre TODAS las hojas y cada una
 * pisa a la anterior, así que se quedaba con la última. Con un archivo de una
 * sola hoja daba igual; con la plantilla, que trae «Instrucciones» y la lista
 * de categorías en hojas aparte, se habría importado la hoja de ayuda en vez
 * de los productos. La hoja 0 es la de los productos en la plantilla y la
 * única en lo que exporta un punto de venta.
 */
class FilasDeCatalogo implements ToCollection, WithHeadingRow, WithMultipleSheets
{
    public Collection $filas;

    public function __construct()
    {
        $this->filas = collect();
    }

    public function sheets(): array
    {
        return [0 => $this];
    }

    public function collection(Collection $rows): void
    {
        $this->filas = $rows;
    }
}

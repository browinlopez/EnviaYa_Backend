<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Lee el archivo y ya. Nada más.
 *
 * Los importadores viejos —`ProductsImport` y `ProductBusinessImport`— mezclan
 * leer el Excel con decidir qué hacer con cada fila, y por eso terminan
 * haciendo `dump()` dentro del bucle: no tienen a quién devolverle el
 * resultado. Separarlo permite que quien decide sea un servicio normal, con
 * pruebas, sin necesidad de un archivo de por medio.
 */
class FilasDeCatalogo implements ToCollection, WithHeadingRow
{
    public Collection $filas;

    public function __construct()
    {
        $this->filas = collect();
    }

    public function collection(Collection $rows): void
    {
        $this->filas = $rows;
    }
}

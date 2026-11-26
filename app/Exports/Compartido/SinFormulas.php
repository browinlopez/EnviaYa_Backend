<?php

declare(strict_types=1);

namespace App\Exports\Compartido;

use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Ninguna celda de nuestros Excel es una FÓRMULA.
 *
 * Los libros llevan textos que escribe un tercero: la torre la pone el
 * comprador en su dirección, el nombre lo escribe cada usuario, el negocio
 * su razón social. Con el binder por defecto, un texto que empieza por «=» se
 * guarda como fórmula, y quien abre el reporte ejecuta lo que otro escribió
 * —un `=HYPERLINK` a otro sitio, o algo peor—. Esos textos se guardan como
 * texto.
 *
 * Solo los que empiezan por «=»: el resto sigue el binder de siempre, para
 * que las cifras que llegan de la base como cadena sigan siendo números que
 * se pueden sumar.
 *
 * TODAS las hojas de `app/Exports` heredan de acá (las del conjunto vía
 * `HojaDelReporte`). No se pone como binder global en `excel.value_binder`
 * porque ese mismo ajuste lo usa el LECTOR de las importaciones del catálogo.
 * Una hoja nueva que se olvide de heredar vuelve a abrir el hueco, y
 * `ExcelSinFormulasTest` recorre las clases de `app/Exports` para notarlo.
 */
abstract class SinFormulas extends DefaultValueBinder implements WithCustomValueBinder
{
    public function bindValue(Cell $cell, $value)
    {
        if (is_string($value) && str_starts_with(ltrim($value), '=')) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}

<?php

declare(strict_types=1);

namespace App\Exports\Conjunto\Hojas;

use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Ninguna celda de este reporte es una FÓRMULA.
 *
 * La torre la escribe el comprador al registrar su dirección y el nombre del
 * domiciliario lo escribe él mismo. Con el binder por defecto, un texto que
 * empieza por «=» se guarda como fórmula, y el administrador que abre el
 * reporte ejecuta lo que un tercero escribió —un `=HYPERLINK` a otro sitio,
 * o algo peor—. Esos textos se guardan como texto.
 *
 * Solo los que empiezan por «=»: el resto sigue el binder de siempre, para
 * que las cifras que llegan de la base como cadena sigan siendo números que
 * el administrador puede sumar.
 *
 * TODAS las hojas del reporte heredan de acá (vía `HojaDelReporte`): una hoja
 * nueva que se olvide de hacerlo vuelve a abrir el hueco, y la prueba de
 * seguridad recorre todas las celdas de todas las hojas para notarlo.
 */
abstract class HojaSinFormulas extends DefaultValueBinder implements WithCustomValueBinder
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

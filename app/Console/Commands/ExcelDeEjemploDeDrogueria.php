<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PlantillaDeCatalogo;
use App\Support\ProductTypeSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * ==========================================================================
 * EL EXCEL DE EJEMPLO DE UNA DROGUERÍA.
 *
 * Para probar la carga masiva sin escribir cuarenta filas a mano, y para que
 * el dueño de una droguería vea cómo se llena antes de llenarla.
 *
 * --------------------------------------------------------------------------
 * SE ARMA CON LA MISMA `PlantillaDeCatalogo` QUE BAJA EL DUEÑO.
 *
 * Es la decisión que sostiene todo lo demás: si el ejemplo se escribiera
 * aparte, el día que la plantilla gane una columna el ejemplo quedaría
 * desalineado --los datos una celda corridos-- y lo que enseñaría es cómo
 * hacerlo mal. Armándolo con la plantilla de verdad, eso no puede pasar.
 *
 * Por lo mismo las columnas NO se nombran acá: se preguntan a la hoja ya
 * generada y los datos se colocan por el título de cada una.
 * ========================================================================== */
class ExcelDeEjemploDeDrogueria extends Command
{
    protected $signature = 'drogueria:excel-de-ejemplo
                            {--negocio= : El id de la droguería; si no, la primera que haya}
                            {--salida= : Dónde escribirlo}';

    protected $description = 'Arma un Excel de ejemplo con productos de droguería, listo para subir';

    public function handle(): int
    {
        $negocioId = $this->option('negocio')
            ? (int) $this->option('negocio')
            : (int) DB::table('business')
                ->where('type', ProductTypeSchema::TYPE_PHARMACY)
                ->orderBy('busines_id')
                ->value('busines_id');

        if (! $negocioId) {
            $this->error('No hay ninguna droguería (business.type = 2) en esta base.');

            return self::FAILURE;
        }

        $negocio = DB::table('business')->where('busines_id', $negocioId)->first(['name', 'type']);

        if ((int) $negocio->type !== ProductTypeSchema::TYPE_PHARMACY) {
            $this->error("El negocio {$negocioId} («{$negocio->name}») no es una droguería.");

            return self::FAILURE;
        }

        $libro = (new PlantillaDeCatalogo)->generar($negocioId);
        $hoja = $libro->getSheetByName('Productos');

        /* Las columnas, preguntadas a la hoja: título => letra. */
        $letraDe = [];

        foreach (range('A', $hoja->getHighestColumn()) as $col) {
            $titulo = $hoja->getCell("{$col}1")->getValue();

            if ($titulo !== null && $titulo !== '') {
                $letraDe[$titulo] = $col;
            }
        }

        /*
         * El orden de cada fila del fichero, dicho por el título de la columna
         * a la que va. Lo que no exista en la plantilla se descarta solo.
         */
        $orden = [
            'Nombre', 'Marca', 'Categoría', 'Precio', 'Cantidad',
            'Presentación', 'Contenido', 'Requiere fórmula',
            'Principio activo', 'Dosis',
        ];

        $productos = require database_path('data/productos_de_drogueria_de_ejemplo.php');

        $fila = 2;
        $puestos = 0;

        foreach ($productos as $p) {
            foreach ($orden as $i => $titulo) {
                $col = $letraDe[$titulo] ?? null;

                if ($col === null || ! array_key_exists($i, $p)) {
                    continue;
                }

                $valor = $p[$i];

                /*
                 * El precio y la cantidad como NÚMERO; el resto como texto
                 * explícito. El catálogo es compartido y un nombre que empiece
                 * por «=» lo tomaría Excel como fórmula, que es el camino por
                 * el que entra basura a una tabla que ven todas las tiendas.
                 */
                if (in_array($titulo, ['Precio', 'Cantidad'], true)) {
                    $hoja->setCellValue("{$col}{$fila}", $valor);
                } else {
                    $hoja->setCellValueExplicit("{$col}{$fila}", (string) $valor, DataType::TYPE_STRING);
                }
            }

            $fila++;
            $puestos++;
        }

        $salida = $this->option('salida')
            ?: storage_path('app/ejemplos/catalogo-drogueria-ejemplo.xlsx');

        if (! is_dir(dirname($salida))) {
            mkdir(dirname($salida), 0775, true);
        }

        (new Xlsx($libro))->save($salida);

        $this->info("{$puestos} productos escritos para «{$negocio->name}».");
        $this->line("  {$salida}");

        /* Lo que va vacío, dicho en voz alta: si no, parece un descuido. */
        $this->newLine();
        $this->comment('El código de barras y el registro INVIMA van VACÍOS a propósito:');
        $this->comment('products.barcode es ÚNICO en el catálogo compartido de la plataforma,');
        $this->comment('y un código inventado quedaría ocupado para siempre.');

        return self::SUCCESS;
    }
}

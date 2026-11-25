<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ==========================================================================
 * LAS FOTOS QUE NO SON DEL PRODUCTO, FUERA.
 *
 * El catálogo arrancó con miniaturas de Google Shopping buscadas POR NOMBRE,
 * y una búsqueda por nombre se queda con lo primero que sale: un polvo
 * compacto «Arroz» para el ARROZ DIANA, unos pañitos para el PAPEL ALPINA.
 * Se revisaron una a una (hojas de contacto con número y nombre) y lo que
 * salió mal está en `database/data/fotos_equivocadas.php`.
 *
 * Una foto equivocada es PEOR que ninguna: sin foto la app pinta el hueco
 * con el icono y el nombre manda; con la foto de otro producto, el cliente
 * pide lo que ve y le llega otra cosa. Cuando la lista trae `foto` --la
 * correcta, buscada en catálogos de tiendas colombianas y verificada a ojo--
 * se pone esa; cuando no, se quita y no se adivina otra.
 *
 * --------------------------------------------------------------------------
 * SOLO SI ES EL MISMO PRODUCTO.
 *
 * La lista se hizo sobre una base concreta. En otra, el número de producto
 * puede ser otro, así que se exige que coincidan el código de barras (si
 * lo hay) o el número Y el nombre. Lo que no casa se cuenta y se salta: es
 * preferible dejar una foto mala que borrar una buena.
 *
 * Y la carga de prueba (`origen = prueba-carga`) pierde todas: son cinco
 * mil productos con nombres inventados --«LECHE BAVARIA», «JABÓN POSTOBÓN»--
 * y ninguna foto puede ser la suya.
 *
 *     php artisan catalogo:quitar-fotos-equivocadas            ensaya
 *     php artisan catalogo:quitar-fotos-equivocadas --aplicar  quita
 * ==========================================================================
 */
class QuitarFotosEquivocadas extends Command
{
    protected $signature = 'catalogo:quitar-fotos-equivocadas
                            {--aplicar : Quitar de verdad; sin esto solo ensaya}';

    protected $description = 'Quita las fotos revisadas que no corresponden a su producto';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $lista = require database_path('data/fotos_equivocadas.php');

        $this->line($aplicar ? 'APLICANDO' : 'ENSAYO (no escribe nada)');

        $ids = [];
        $correctas = [];
        $saltados = 0;

        foreach ($lista as $f) {
            $q = DB::table('products');
            if (! empty($f['barcode'])) {
                $q->where('barcode', $f['barcode']);
            } else {
                $q->where('products_id', $f['id'])->where('name', $f['nombre']);
            }
            $encontrado = $q->value('products_id');

            if ($encontrado === null) {
                $saltados++;

                continue;
            }
            // Con la foto buena se reemplaza; sin ella, se quita la mala.
            if (! empty($f['foto'])) {
                $correctas[(int) $encontrado] = $f['foto'];
            } else {
                $ids[] = (int) $encontrado;
            }
        }

        $dePrueba = DB::table('products')
            ->where('origen', 'prueba-carga')
            ->whereNotNull('image')->where('image', '<>', '')
            ->pluck('products_id')
            ->all();

        $todos = array_values(array_unique(array_merge($ids, $dePrueba)));

        $this->line('  Con su foto correcta: '.count($correctas));
        $this->line('  Sin foto correcta, se quita: '.count($ids).' (sin coincidencia, saltados: '.$saltados.')');
        $this->line('  De la carga de prueba: '.count($dePrueba));

        if ($aplicar) {
            foreach ($correctas as $id => $foto) {
                DB::table('products')->where('products_id', $id)->update(['image' => $foto]);
            }
            if ($todos) {
                DB::table('products')->whereIn('products_id', $todos)->update(['image' => null]);
            }
            // Los de la carga de prueba que SÍ son un producto real llevan su foto.
            $reales = require database_path('data/fotos_de_la_carga_de_prueba.php');
            foreach ($reales as $prefijo => $foto) {
                DB::table('products')
                    ->where('origen', 'prueba-carga')
                    ->where('name', 'like', $prefijo.' %')
                    ->update(['image' => $foto]);
            }
            $this->info('  Corregidas: '.count($correctas).' · Quitadas: '.count($todos));
        }

        return self::SUCCESS;
    }
}

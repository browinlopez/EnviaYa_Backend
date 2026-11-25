<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ==========================================================================
 * CADA PRODUCTO EN SU CATEGORÍA, Y CADA CATEGORÍA EN SU OFICIO.
 *
 * Una revisión producto por producto encontró salsas en «Bebidas», galletas
 * en «Bebidas», desodorantes en «Higiene oral», melatonina en «Gripa y tos»
 * y quesos en el catálogo de una droguería. Y, de fondo, que la tienda
 * enseñaba «Medicina» y «Cuidado Personal», que son categorías de droguería,
 * porque la categoría vivía solo en el producto compartido.
 *
 * Esto aplica `database/data/categorias_revisadas.php` en tres pasos:
 *
 *  1. La categoría correcta de cada producto revisado.
 *  2. Fuera de las droguerías, los alimentos que se colaron.
 *  3. En cada TIENDA, lo que tiene categoría de droguería pasa a la
 *     categoría propia de la tienda (`products_business.category_id`).
 *
 * Se puede correr las veces que haga falta: cada paso deja el mismo
 * resultado la segunda vez.
 *
 *     php artisan catalogo:ordenar-categorias            ensaya
 *     php artisan catalogo:ordenar-categorias --aplicar  aplica
 * ==========================================================================
 */
class OrdenarCategorias extends Command
{
    protected $signature = 'catalogo:ordenar-categorias
                            {--aplicar : Aplicar de verdad; sin esto solo ensaya}';

    protected $description = 'Corrige categorías revisadas y separa las de tienda y droguería';

    private const TIENDA = 1;

    private const DROGUERIA = 2;

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $datos = require database_path('data/categorias_revisadas.php');
        $this->line($aplicar ? 'APLICANDO' : 'ENSAYO (no escribe nada)');

        $idDe = fn (string $nombre) => DB::table('category')->where('name', $nombre)->value('category_id');

        // 1. La categoría correcta de cada producto.
        $cambian = 0;
        foreach ($datos['producto'] as $nombre => $ids) {
            $cat = $idDe($nombre);
            if (! $cat) {
                $this->warn("  No existe la categoría «{$nombre}»; se salta.");

                continue;
            }
            $q = DB::table('products')->whereIn('products_id', $ids)->where('category_id', '<>', $cat);
            $cambian += (clone $q)->count();
            if ($aplicar) {
                $q->update(['category_id' => $cat]);
            }
        }
        $this->line("  Productos con categoría corregida: {$cambian}");

        // 2. Los alimentos fuera de las droguerías.
        $droguerias = DB::table('business')->where('type', self::DROGUERIA)->pluck('busines_id');
        $q = DB::table('products_business')
            ->whereIn('busines_id', $droguerias)
            ->whereIn('products_id', $datos['fuera_de_drogueria']);
        $this->line('  Alimentos sacados de las droguerías: '.(clone $q)->count());
        if ($aplicar) {
            $q->delete();
        }

        // 3. En las tiendas, la categoría de tienda para lo que es de droguería.
        $tiendas = DB::table('business')->where('type', self::TIENDA)->pluck('busines_id');
        $enTienda = 0;
        foreach ($datos['en_tienda'] as $deDrogueria => $deTienda) {
            $desde = DB::table('category')->where('name', $deDrogueria)->where('business_category_id', self::DROGUERIA)->value('category_id');
            $hacia = DB::table('category')->where('name', $deTienda)->where('business_category_id', self::TIENDA)->value('category_id');
            if (! $desde || ! $hacia) {
                continue;
            }
            $q = DB::table('products_business')
                ->whereIn('busines_id', $tiendas)
                ->whereIn('products_id', DB::table('products')->where('category_id', $desde)->select('products_id'))
                ->where(fn ($w) => $w->whereNull('category_id')->orWhere('category_id', '<>', $hacia));
            $enTienda += (clone $q)->count();
            if ($aplicar) {
                $q->update(['category_id' => $hacia]);
            }
        }
        $this->line("  En tiendas, pasados a categoría de tienda: {$enTienda}");

        return self::SUCCESS;
    }
}

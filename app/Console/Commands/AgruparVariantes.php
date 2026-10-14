<?php

namespace App\Console\Commands;

use App\Models\Product\Product;
use App\Models\Product\ProductGroup;
use App\Services\AgrupadorDeVariantes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Siembra los grupos de variantes del catálogo.
 *
 *     php artisan catalogo:agrupar              ensaya y enseña lo que haría
 *     php artisan catalogo:agrupar --aplicar    escribe
 *
 * SE PUEDE VOLVER A CORRER. Cada vez que entra un catálogo nuevo aparecen
 * variantes de cosas que antes tenían una sola presentación, así que esto no
 * es una migración de una vez: es mantenimiento. Por eso busca el grupo por su
 * núcleo antes de crear uno, y por eso NO TOCA los grupos con `origen` =
 * 'equipo' —los que alguien corrigió a mano— ni los productos que ya cuelgan
 * de uno de ellos. El trabajo de una persona no lo pisa un algoritmo que se
 * ejecuta solo.
 */
class AgruparVariantes extends Command
{
    protected $signature = 'catalogo:agrupar
                            {--aplicar : Escribir los cambios en la base}
                            {--categoria= : Limitar a una categoría}';

    protected $description = 'Agrupa los productos que son el mismo con otra presentación';

    public function handle(AgrupadorDeVariantes $agrupador): int
    {
        $aplicar = (bool) $this->option('aplicar');

        $consulta = Product::query()
            ->select('products_id', 'name', 'barcode', 'brand', 'category_id', 'image', 'group_id')
            ->where('state', 1);

        if ($categoria = $this->option('categoria')) {
            $consulta->where('category_id', (int) $categoria);
        }

        $productos = $consulta->get();

        $this->newLine();
        $this->line($aplicar ? '  <options=bold>APLICANDO</>' : '  <options=bold>ENSAYO</> (no escribe nada)');
        $this->newLine();
        $this->line(sprintf('  Productos mirados: %d', $productos->count()));

        /*
         * Los que ya cuelgan de un grupo revisado a mano se quedan fuera del
         * cálculo: si entraran, el agrupador podría proponer moverlos y el
         * `--aplicar` desharía una corrección humana.
         */
        $gruposDeEquipo = ProductGroup::where('origen', 'equipo')->pluck('group_id')->all();
        $intocables = $gruposDeEquipo
            ? Product::whereIn('group_id', $gruposDeEquipo)->pluck('products_id')->all()
            : [];

        if ($intocables) {
            $this->line(sprintf('  Respetados por venir de una revisión a mano: %d', count($intocables)));
            $productos = $productos->whereNotIn('products_id', $intocables);
        }

        $grupos = $agrupador->agrupar($productos->map(fn ($p) => $p->toArray()));

        $enGrupos = array_sum(array_map(fn ($g) => count($g['miembros']), $grupos));

        $this->newLine();
        $this->line(sprintf('  Grupos con 2 o más variantes: <options=bold>%d</>', count($grupos)));
        $this->line(sprintf('  Productos que quedan agrupados: <options=bold>%d</> (%.1f%%)',
            $enGrupos, $productos->count() ? 100 * $enGrupos / $productos->count() : 0));
        $this->newLine();

        $mayores = collect($grupos)->sortByDesc(fn ($g) => count($g['miembros']))->take(12);

        foreach ($mayores as $g) {
            $this->line(sprintf('  <options=bold>%s</> (%d variantes)', $g['name'], count($g['miembros'])));
            foreach (collect($g['miembros'])->sortBy('name')->take(6) as $m) {
                $this->line(sprintf('      %-46s  <fg=cyan>%s</>',
                    mb_strimwidth($m['name'], 0, 46, '…'), $m['_etiqueta']));
            }
        }

        if (!$aplicar) {
            $this->newLine();
            $this->line('  Para escribir: <options=bold>php artisan catalogo:agrupar --aplicar</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $creados = 0;
        $reusados = 0;
        $asignados = 0;
        $borrados = 0;
        $sellados = [];

        DB::transaction(function () use ($grupos, &$creados, &$reusados, &$asignados, &$borrados, &$sellados) {
            foreach ($grupos as $g) {
                /*
                 * El núcleo es la identidad del grupo, no su nombre: el nombre
                 * puede cambiar cuando entra una variante con un nombre más
                 * corto, y entonces se crearía un grupo duplicado al lado del
                 * que ya existía.
                 */
                $existente = ProductGroup::where('name', $g['name'])
                    ->where('category_id', $g['category_id'])
                    ->first();

                if ($existente) {
                    $grupo = $existente;
                    $reusados++;
                } else {
                    $grupo = ProductGroup::create([
                        'name'        => $g['name'],
                        'brand'       => $g['brand'],
                        'category_id' => $g['category_id'],
                        'image'       => $g['image'],
                        'origen'      => 'automatico',
                    ]);
                    $creados++;
                }

                foreach ($g['miembros'] as $m) {
                    $asignados += Product::where('products_id', $m['products_id'])
                        ->update([
                            'group_id'  => $grupo->group_id,
                            'variante'  => $m['_etiqueta'],
                        ]);

                    $sellados[] = (int) $m['products_id'];
                }
            }

            /*
             * SOLTAR LO QUE YA NO PERTENECE, Y BORRAR LO QUE QUEDÓ VACÍO.
             *
             * Esto se vuelve a correr cada vez que entra un catálogo o se
             * afina el vocabulario, y entonces un producto puede cambiar de
             * grupo o dejar de tener uno. Sin esta parte quedaban dos rastros,
             * los dos invisibles desde fuera: productos apuntando a un grupo
             * del que ya no son, y grupos sin un solo miembro que la app
             * pintaría como una tarjeta vacía.
             *
             * Sólo se tocan los automáticos: un grupo que alguien revisó a
             * mano se queda aunque el algoritmo de hoy no lo habría propuesto.
             */
            $automaticos = ProductGroup::where('origen', 'automatico')->pluck('group_id');

            Product::whereIn('group_id', $automaticos)
                ->whereNotIn('products_id', $sellados ?: [0])
                ->update(['group_id' => null]);

            $vacios = ProductGroup::where('origen', 'automatico')
                ->whereDoesntHave('products')
                ->pluck('group_id');

            $borrados = $vacios->count();

            if ($borrados) {
                ProductGroup::whereIn('group_id', $vacios)->delete();
            }
        });

        $this->newLine();
        $this->line(sprintf('  Grupos creados: <options=bold>%d</>  reutilizados: %d', $creados, $reusados));
        $this->line(sprintf('  Productos asignados a un grupo: <options=bold>%d</>', $asignados));

        if ($borrados) {
            $this->line(sprintf('  Grupos vacíos borrados: %d', $borrados));
        }

        $sueltos = Product::whereNull('group_id')->where('state', 1)->count();
        $this->line(sprintf('  Productos sin grupo (se venden en una sola presentación): %d', $sueltos));
        $this->newLine();

        return self::SUCCESS;
    }
}

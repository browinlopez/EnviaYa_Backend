<?php

namespace App\Console\Commands;

use App\Models\Product\Product;
use App\Models\Product\ProductAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Los nombres con los que cada tienda llama a un producto del catálogo.
 *
 *     php artisan catalogo:alias                      ensaya
 *     php artisan catalogo:alias --aplicar            siembra los que faltan
 *     php artisan catalogo:alias --producto=8 --nombre="ACETAMINOFEN" --aplicar
 *
 * SIN ARGUMENTOS siembra el nombre propio de cada producto que no lo tenga.
 * Eso es lo que hace que una carga reconozca «AJINOMEN CARNE  80 GR» como
 * «AJINOMEN CARNE 80 GR» —un espacio de más— en vez de crear un duplicado en
 * el catálogo compartido de todas las tiendas.
 *
 * CON `--producto` Y `--nombre` registra una decisión humana: que el archivo
 * de una tienda llame «ACETAMINOFEN» a lo que el catálogo tiene como
 * «ACETAMINOFEN TABLETAS 500MG XUNIDAD». Eso el importador no lo puede
 * deducir, y si lo intentara por parecido juntaría «ÁGUILA NEGRA» con
 * «ÁGUILA», que son dos cervezas.
 *
 * SE PUEDE VOLVER A CORRER. No toca lo que ya existe; solo añade lo que falta.
 */
class AliasDeCatalogo extends Command
{
    protected $signature = 'catalogo:alias
                            {--aplicar : Escribir los cambios en la base}
                            {--producto= : Registrar un alias para este producto}
                            {--nombre= : El nombre con el que se le llama}
                            {--negocio= : Solo para esta tienda; si falta, vale para todas}';

    protected $description = 'Siembra y registra los nombres con los que se llama a cada producto';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        if ($this->option('producto') || $this->option('nombre')) {
            return $this->registrarUno($aplicar);
        }

        return $this->sembrarNombresPropios($aplicar);
    }

    /**
     * Una decisión humana: «este archivo llama así a este producto».
     */
    private function registrarUno(bool $aplicar): int
    {
        $productoId = (int) $this->option('producto');
        $nombre = (string) $this->option('nombre');

        if (!$productoId || $nombre === '') {
            $this->error('Hacen falta --producto y --nombre.');

            return self::FAILURE;
        }

        $producto = Product::find($productoId);

        if (!$producto) {
            $this->error("No existe el producto {$productoId}.");

            return self::FAILURE;
        }

        $alias = ProductAlias::normalizar($nombre);
        $negocio = $this->option('negocio') ? (int) $this->option('negocio') : ProductAlias::TODAS;

        $this->newLine();
        $this->line(sprintf('  «%s»  ->  [%d] %s', $alias, $producto->products_id, $producto->name));
        $this->line($negocio === ProductAlias::TODAS
            ? '  para todas las tiendas'
            : "  solo para el negocio {$negocio}");

        $ocupado = ProductAlias::where('alias', $alias)->where('busines_id', $negocio)->first();

        if ($ocupado && (int) $ocupado->products_id !== $productoId) {
            $this->warn(sprintf('  Ya apuntaba a [%d]. Se reemplaza.', $ocupado->products_id));
        }

        if (!$aplicar) {
            $this->newLine();
            $this->line('  Nada se escribió. Añade <options=bold>--aplicar</>');
            $this->newLine();

            return self::SUCCESS;
        }

        ProductAlias::updateOrCreate(
            ['alias' => $alias, 'busines_id' => $negocio],
            ['products_id' => $productoId, 'origen' => 'equipo'],
        );

        $this->info('  Registrado.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * El nombre propio de cada producto, para los que no lo tengan.
     *
     * Un nombre que corresponde a DOS productos se queda sin alias a
     * propósito: el catálogo tiene «PANELA» dos veces, y dejar el alias
     * apuntando a uno haría que una carga eligiera en silencio. Sin alias, la
     * carga rechaza la fila pidiendo el código de barras, que es la pregunta
     * correcta.
     */
    private function sembrarNombresPropios(bool $aplicar): int
    {
        $this->newLine();
        $this->line($aplicar ? '  <options=bold>APLICANDO</>' : '  <options=bold>ENSAYO</> (no escribe nada)');
        $this->newLine();

        $yaTienen = ProductAlias::where('busines_id', ProductAlias::TODAS)
            ->pluck('alias')
            ->flip();

        $porAlias = [];
        $vacios = 0;

        Product::select('products_id', 'name')->orderBy('products_id')
            ->chunk(500, function ($productos) use (&$porAlias, &$vacios) {
                foreach ($productos as $p) {
                    $alias = ProductAlias::normalizar((string) $p->name);

                    if ($alias === '') {
                        $vacios++;
                        continue;
                    }

                    $porAlias[$alias][] = (int) $p->products_id;
                }
            });

        $nuevos = [];
        $ambiguos = [];

        foreach ($porAlias as $alias => $ids) {
            if ($yaTienen->has($alias)) {
                continue;
            }

            if (count($ids) > 1) {
                $ambiguos[$alias] = $ids;
                continue;
            }

            $nuevos[] = [
                'products_id' => $ids[0],
                'alias'       => $alias,
                'busines_id'  => ProductAlias::TODAS,
                'origen'      => 'migracion',
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
        }

        $this->line(sprintf('  Productos mirados:            %d', array_sum(array_map('count', $porAlias))));
        $this->line(sprintf('  Nombres que ya tienen alias:  %d', $yaTienen->count()));
        $this->line(sprintf('  A sembrar:                    <options=bold>%d</>', count($nuevos)));
        $this->line(sprintf('  Ambiguos (se quedan sin alias): %d', count($ambiguos)));

        if ($vacios) {
            $this->line(sprintf('  Sin nombre utilizable:        %d', $vacios));
        }

        foreach (array_slice($ambiguos, 0, 8, true) as $alias => $ids) {
            $this->line(sprintf('      <fg=yellow>%s</> -> %s', $alias, implode(', ', $ids)));
        }

        if (!$aplicar) {
            $this->newLine();
            $this->line('  Para escribir: <options=bold>php artisan catalogo:alias --aplicar</>');
            $this->newLine();

            return self::SUCCESS;
        }

        foreach (array_chunk($nuevos, 500) as $tanda) {
            DB::table('product_aliases')->insertOrIgnore($tanda);
        }

        $total = ProductAlias::count();

        $this->newLine();
        $this->line(sprintf('  Sembrados: <options=bold>%d</>. La tabla queda con %d.', count($nuevos), $total));
        $this->newLine();

        return self::SUCCESS;
    }
}

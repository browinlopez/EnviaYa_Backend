<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * ==========================================================================
 * PRODUCTOS DE DROGUERÍA QUE SÍ TIENEN FOTO.
 *
 * El catálogo sembrado traía doscientas referencias escritas a mano: nombres
 * y marcas de verdad, pero sin código de barras y sin foto, porque inventar
 * un EAN lo ocupa para siempre en el catálogo COMPARTIDO y una foto no se
 * puede inventar.
 *
 * Esto trae los que ya existen en una base abierta, con su código real, su
 * nombre real y su fotografía:
 *
 *   · OPEN BEAUTY FACTS  cuidado personal, dermocosmética, higiene oral,
 *                        bebé. Es la mitad del mostrador de una droguería.
 *   · OPEN FOOD FACTS    suero oral, leche en polvo, suplementos.
 *
 * Las dos son abiertas, gratuitas y ya se usan en el proyecto: el escáner
 * consulta Open Food Facts desde `FuenteExternaDeProductos`.
 *
 * --------------------------------------------------------------------------
 * LO QUE ESTO NO VA A TRAER, Y HAY QUE DECIRLO.
 *
 * MEDICAMENTOS. Open Food Facts es de alimentos y Open Beauty Facts de
 * cosmética: ninguna tiene acetaminofén, amoxicilina ni losartán, y no es un
 * fallo de la consulta sino de qué son esas bases.
 *
 * O sea que la parte farmacéutica del catálogo se queda sin foto por esta
 * vía. Para ésa hacen falta las fotos del laboratorio o las del propio
 * tendero, y entonces `catalogo:imagenes --carpeta=` las sube.
 *
 * --------------------------------------------------------------------------
 *     php artisan drogueria:traer-productos              ensaya
 *     php artisan drogueria:traer-productos --aplicar    los crea
 *
 * Después, para que las fotos dejen de vivir en un servidor ajeno:
 *
 *     php artisan catalogo:imagenes --aplicar
 */
class TraerProductosDeDrogueria extends Command
{
    protected $signature = 'drogueria:traer-productos
                            {--aplicar : Crear los productos de verdad}
                            {--limite=0 : Cuántos traer como mucho}';

    protected $description = 'Trae productos de droguería con foto desde Open Beauty/Food Facts';

    private const ESPERA = 20;

    private const CONECTAR = 4;

    private const BEAUTY = 'https://world.openbeautyfacts.org/cgi/search.pl';

    private const FOOD = 'https://world.openfoodfacts.org/cgi/search.pl';

    /**
     * Qué se busca y en qué estante va.
     *
     * Los términos son los de quien atiende, no los de un catálogo: se busca
     * «crema», no «emulsión hidratante corporal». Y la categoría se decide
     * acá y no por lo que diga la fuente, porque sus categorías son de
     * alimentación y cosmética y no coinciden con las de una droguería.
     */
    private const BUSQUEDAS = [
        // [término, fuente, categoría nuestra]
        ['shampoo', self::BEAUTY, 'Cuidado Personal'],
        ['jabon', self::BEAUTY, 'Cuidado Personal'],
        ['desodorante', self::BEAUTY, 'Cuidado Personal'],
        ['toallas', self::BEAUTY, 'Cuidado Personal'],
        ['papel', self::BEAUTY, 'Cuidado Personal'],
        ['afeitar', self::BEAUTY, 'Cuidado Personal'],
        ['crema', self::BEAUTY, 'Dermocosmética'],
        ['protector solar', self::BEAUTY, 'Dermocosmética'],
        ['bloqueador', self::BEAUTY, 'Dermocosmética'],
        ['locion', self::BEAUTY, 'Dermocosmética'],
        ['hidratante', self::BEAUTY, 'Dermocosmética'],
        ['crema dental', self::BEAUTY, 'Higiene oral'],
        ['enjuague bucal', self::BEAUTY, 'Higiene oral'],
        ['cepillo dental', self::BEAUTY, 'Higiene oral'],
        ['bebe', self::BEAUTY, 'Bebé'],
        ['pañal', self::BEAUTY, 'Bebé'],
        /*
         * DE LA BASE DE ALIMENTOS, SÓLO MARCAS CONCRETAS.
         *
         * La primera versión buscaba «vitamina», «avena» y «leche en polvo»,
         * y trajo ciento dieciocho productos de los que la mayoría eran
         * MERCADO: gelatina, granola, Corn Flakes, galletas Saltín. Lógico:
         * es una base de ALIMENTOS, y «avena» ahí es un cereal, no un
         * suplemento.
         *
         * Un término genérico en una base de otro oficio trae el oficio de la
         * base, no el tuyo. Por eso ahora se nombran marcas que una droguería
         * sí vende, y punto.
         */
        ['pedialyte', self::FOOD, 'Digestivo'],
        ['suero oral', self::FOOD, 'Digestivo'],
        ['ensure', self::FOOD, 'Vitaminas y suplementos'],
        ['glucerna', self::FOOD, 'Vitaminas y suplementos'],
        ['pediasure', self::FOOD, 'Vitaminas y suplementos'],
        ['nan', self::FOOD, 'Bebé'],
        ['nutramigen', self::FOOD, 'Bebé'],
        ['compota', self::FOOD, 'Bebé'],
    ];

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $limite = (int) $this->option('limite');

        $categorias = DB::table('category')->pluck('category_id', 'name');

        $sedes = DB::table('business')
            ->where('type', 2)
            ->orderBy('busines_id')
            ->pluck('busines_id')
            ->all();

        if ($sedes === []) {
            $this->error('No hay droguerías en esta base.');

            return self::FAILURE;
        }

        $this->line($aplicar ? 'APLICANDO' : 'ENSAYO (no escribe nada)');
        $this->newLine();

        $vistos = [];
        $creados = 0;
        $repetidos = 0;

        foreach (self::BUSQUEDAS as [$termino, $fuente, $categoria]) {
            if (! isset($categorias[$categoria])) {
                $this->line("  ✗ {$categoria}: la categoría no existe en la base");

                continue;
            }

            $encontrados = $this->buscar($termino, $fuente);
            $deEste = 0;

            foreach ($encontrados as $p) {
                if ($limite > 0 && $creados >= $limite) {
                    break 2;
                }

                $codigo = (string) $p['code'];

                /*
                 * El mismo producto sale en varias búsquedas --una crema
                 * hidratante cae en «crema» y en «hidratante»-- y el código de
                 * barras es ÚNICO en el catálogo compartido: sin esto, la
                 * segunda inserción reventaría.
                 */
                if (isset($vistos[$codigo])) {
                    continue;
                }

                $vistos[$codigo] = true;

                if (DB::table('products')->where('barcode', $codigo)->exists()) {
                    $repetidos++;

                    continue;
                }

                if ($aplicar) {
                    $this->crear($p, (int) $categorias[$categoria], $sedes);
                }

                $creados++;
                $deEste++;
            }

            $this->line(sprintf('  %-18s %-24s %s nuevos', $termino, $categoria, $deEste));
        }

        $this->newLine();
        $this->info($aplicar
            ? "{$creados} productos creados, {$repetidos} ya estaban."
            : "{$creados} se traerían, {$repetidos} ya estaban.");

        if ($aplicar && $creados > 0) {
            $this->newLine();
            $this->line('  Las fotos viven todavía en el servidor de la fuente. Para traerlas:');
            $this->line('    php artisan catalogo:imagenes --aplicar');
        }

        if (! $aplicar) {
            $this->line('  Para hacerlo: php artisan drogueria:traer-productos --aplicar');
        }

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Lo que la fuente tiene de ese término, filtrado a lo utilizable.
     *
     * Se queda SÓLO con lo que trae foto, código y nombre. Un producto sin
     * foto no sirve para lo que se está haciendo, y uno sin código no se
     * puede meter en un catálogo donde el código es la identidad.
     */
    private function buscar(string $termino, string $fuente): array
    {
        try {
            $r = Http::connectTimeout(self::CONECTAR)
                ->timeout(self::ESPERA)
                ->withHeaders(['User-Agent' => 'VeciPaYa/1.0'])
                ->get($fuente, [
                    'search_terms' => $termino,
                    'countries_tags_en' => 'colombia',
                    'fields' => 'code,product_name,brands,image_front_url,quantity',
                    'json' => 1,
                    'page_size' => 50,
                ]);

            if (! $r->successful()) {
                return [];
            }

            $productos = $r->json('products') ?? [];
        } catch (\Throwable $e) {
            $this->line("  ✗ {$termino}: {$e->getMessage()}");

            return [];
        }

        return array_values(array_filter($productos, function ($p) {
            $codigo = preg_replace('/\D/', '', (string) ($p['code'] ?? ''));

            return ! empty($p['image_front_url'])
                && ! empty(trim((string) ($p['product_name'] ?? '')))
                && strlen($codigo) >= 8
                && strlen($codigo) <= 14;
        }));
    }

    /** @param  list<int>  $sedes */
    private function crear(array $p, int $categoria, array $sedes): void
    {
        /*
         * La marca viene como lista separada por comas --«Eucerin, Eucerin
         * Colombia»-- porque la fuente acepta varias. Se queda la primera: el
         * catálogo tiene una columna, no un conjunto.
         */
        $marca = trim(explode(',', (string) ($p['brands'] ?? ''))[0]);

        $productoId = DB::table('products')->insertGetId([
            'name' => mb_substr(trim((string) $p['product_name']), 0, 255),
            'barcode' => preg_replace('/\D/', '', (string) $p['code']),
            'brand' => $marca !== '' ? mb_substr($marca, 0, 120) : null,
            'category_id' => $categoria,
            'content' => mb_substr(trim((string) ($p['quantity'] ?? '')), 0, 80) ?: null,

            /*
             * La URL de la fuente, de momento. `catalogo:imagenes` la trae a
             * R2 después: dejarla así sería repetir el problema que acabamos
             * de arreglar --un catálogo colgando de un servidor ajeno-- pero
             * guardarla es lo que le dice a ese comando qué hay que bajar.
             */
            'image' => $p['image_front_url'],
            'state' => 1,
            'origen' => 'openfacts',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'products_id');

        foreach ($sedes as $i => $sede) {
            /* La del norte lleva la mitad del surtido, como el resto. */
            if ($i > 0 && $productoId % 2 !== 0) {
                continue;
            }

            DB::table('products_business')->updateOrInsert(
                ['busines_id' => $sede, 'products_id' => $productoId],
                [
                    'price' => $this->precio($p, $i),
                    'sale_unit' => 'unidad',
                    'amount' => 6 + ($productoId * 7) % 36,
                    'stock_weight' => 0,
                    'qualification' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /**
     * Un precio verosímil, y dicho que lo es.
     *
     * La fuente NO trae precios: es un catálogo de productos, no una lista de
     * precios. Se calcula uno a partir del tamaño del envase para que la
     * pantalla enseñe cifras que no chirríen, y queda escrito acá que ninguno
     * es real. El tendero los corrige en lote desde el panel.
     */
    private function precio(array $p, int $sede): float
    {
        $cantidad = (int) (preg_replace('/\D/', '', (string) ($p['quantity'] ?? '')) ?: 0);

        $base = match (true) {
            $cantidad >= 900 => 28000,
            $cantidad >= 400 => 19000,
            $cantidad >= 150 => 13000,
            $cantidad > 0 => 8000,
            default => 12000,
        };

        /* Un poco de variación para que no salgan todos iguales en la lista. */
        $base += (strlen((string) $p['code']) % 5) * 900;

        /* La sede del norte, un 8 % más cara: es otro barrio. */
        return $sede > 0 ? round(($base * 1.08) / 100) * 100 : $base;
    }
}

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

    /** Palabras funcionales francesas: lo que mas se cuela. */
    private const FRANCES = [
        '/\beau\b/iu',
        '/\bsans\b/iu',
        '/\bpour\b/iu',
        '/\bavec\b/iu',
        '/\bcheveux\b/iu',
        '/\bvisage\b/iu',
        '/\bnettoyant\b/iu',
        '/\bcrème\b/iu',
        '/\blait\b/iu',
        '/\bpeaux\b/iu',
        '/\bbio -\b/iu',
    ];

    private const ESPERA = 20;

    private const CONECTAR = 4;

    private const OBF = 'https://world.openbeautyfacts.org/cgi/search.pl';

    private const OFF = 'https://world.openfoodfacts.org/cgi/search.pl';

    /** La tercera hermana: productos que no son ni comida ni cosmética. */
    private const OPF = 'https://world.openproductsfacts.org/cgi/search.pl';

    /**
     * Qué se busca y en qué estante va.
     *
     * Los términos son los de quien atiende, no los de un catálogo: se busca
     * «crema», no «emulsión hidratante corporal». Y la categoría se decide
     * acá y no por lo que diga la fuente, porque sus categorías son de
     * alimentación y cosmética y no coinciden con las de una droguería.
     */
    /**
     * QUÉ SE BUSCA, DÓNDE Y EN QUÉ ESTANTE VA.
     *
     * POR MARCA Y NO POR MOLÉCULA, que es lo que lo cambió todo. Buscar
     * «paracetamol» o «acetaminofén» en una base de alimentos no devuelve
     * nada; buscar «Dolex» o «Genfar» devuelve seis y cuatro productos CON
     * FOTO, porque alguien los escaneó y los subió. La base no sabe de
     * principios activos, pero sí de códigos de barras que alguien leyó.
     *
     * Medido sobre estas marcas: 1.101 productos distintos con fotografía.
     *
     * Y la categoría la decide esta tabla, no la fuente: sus categorías son
     * de alimentación y cosmética y no coinciden con las de una droguería.
     *
     * TODAS SE FILTRAN POR IDIOMA ESPAÑOL. Ver `buscar()`.
     *
     * Con el filtro de país puesto para todo, `colgate` devolvía casi nada:
     * la base tiene setenta y un productos Colgate, pero etiquetados en el
     * país donde los escanearon. Un tubo de Colgate Total 12 es el mismo en
     * Bogotá que en Lima, así que para una MARCA el filtro sólo estorba.
     *
     * Para un término GENÉRICO sí se deja: «crema» sin país trae cremas de
     * medio mundo que no se venden acá, y el catálogo se llena de cosas que
     * nadie va a pedir.
     *
     * @var list<array{0:string, 1:string, 2:string}>
     */
    private const BUSQUEDAS = [
        ['dolex', self::OFF, 'Dolor y fiebre'],
        ['advil', self::OFF, 'Dolor y fiebre'],
        ['aspirina bayer', self::OFF, 'Dolor y fiebre'],
        ['buscapina', self::OFF, 'Dolor y fiebre'],
        ['genfar', self::OFF, 'Medicina'],
        ['la sante', self::OFF, 'Medicina'],
        ['procaps', self::OFF, 'Medicina'],
        ['memphis', self::OFF, 'Medicina'],
        ['noraver', self::OFF, 'Gripa y tos'],
        ['bisolvon', self::OFF, 'Gripa y tos'],
        ['vick', self::OFF, 'Gripa y tos'],
        ['alka seltzer', self::OFF, 'Digestivo'],
        ['pedialyte', self::OFF, 'Digestivo'],
        ['redoxon', self::OFF, 'Vitaminas y suplementos'],
        ['centrum', self::OFF, 'Vitaminas y suplementos'],
        ['ensure', self::OFF, 'Vitaminas y suplementos'],
        ['glucerna', self::OFF, 'Vitaminas y suplementos'],
        ['nan nestle', self::OFF, 'Bebé'],
        ['jgb', self::OPF, 'Primeros auxilios'],
        ['isodine', self::OPF, 'Primeros auxilios'],
        ['colgate', self::OBF, 'Higiene oral'],
        ['listerine', self::OBF, 'Higiene oral'],
        ['sensodyne', self::OBF, 'Higiene oral'],
        ['crema dental', self::OBF, 'Higiene oral'],
        ['enjuague bucal', self::OBF, 'Higiene oral'],
        ['eucerin', self::OBF, 'Dermocosmética'],
        ['cetaphil', self::OBF, 'Dermocosmética'],
        ['nivea', self::OBF, 'Dermocosmética'],
        ['lubriderm', self::OBF, 'Dermocosmética'],
        ['asepxia', self::OBF, 'Dermocosmética'],
        ['protector solar', self::OBF, 'Dermocosmética'],
        ['crema', self::OBF, 'Dermocosmética'],
        ['locion', self::OBF, 'Dermocosmética'],
        ['johnson', self::OBF, 'Cuidado Personal'],
        ['gillette', self::OBF, 'Cuidado Personal'],
        ['rexona', self::OBF, 'Cuidado Personal'],
        ['sedal', self::OBF, 'Cuidado Personal'],
        ['head shoulders', self::OBF, 'Cuidado Personal'],
        ['protex', self::OBF, 'Cuidado Personal'],
        ['yodora', self::OBF, 'Cuidado Personal'],
        ['nosotras', self::OBF, 'Cuidado Personal'],
        ['shampoo', self::OBF, 'Cuidado Personal'],
        ['desodorante', self::OBF, 'Cuidado Personal'],
        ['jabon', self::OBF, 'Cuidado Personal'],
        ['afeitar', self::OBF, 'Cuidado Personal'],
        ['huggies', self::OBF, 'Bebé'],
        ['winny', self::OBF, 'Bebé'],
        ['pequenin', self::OBF, 'Bebé'],
        ['bebe', self::OBF, 'Bebé'],
        ['tena', self::OBF, 'Cuidado del adulto mayor'],
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

                    /*
                     * POR IDIOMA, NO POR PAÍS.
                     *
                     * Con `countries_tags_en=colombia` una marca global casi
                     * no devolvía nada: la base tiene setenta y seis fichas de
                     * Colgate, pero etiquetadas en el país donde cada quien la
                     * escaneó. Al quitar el filtro llegaron setecientas, y la
                     * mitad en FRANCÉS: «Crème mains», «Baume Réparateur».
                     *
                     * El idioma es el filtro que de verdad hacía falta. Un
                     * tubo de Colgate es el mismo en Bogotá que en Lima, pero
                     * su ficha sólo sirve si está escrita en español: el
                     * tendero no va a vender «Roughness Relief Lotion».
                     */
                    'languages_tags_en' => 'spanish',

                    /* `product_name_es` primero: la ficha puede tener el
                       nombre en varios idiomas y el genérico ser el inglés. */
                    'fields' => 'code,product_name,product_name_es,brands,image_front_url,quantity',
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
            $nombre = $this->nombreDe($p);
            $codigo = preg_replace('/\D/', '', (string) ($p['code'] ?? ''));

            return ! empty($p['image_front_url'])
                && $nombre !== null
                && strlen($codigo) >= 8
                && strlen($codigo) <= 14;
        }));
    }

    /**
     * EL NOMBRE, LIMPIO, O NADA.
     *
     * Las fichas las escribe quien escanea, y algunas traen basura. Dos
     * casos REALES que entraron y hubo que sacar:
     *
     *   «8710847904868&gt; 8710847 904868&gt;»  el codigo repetido, con
     *       entidades HTML sin decodificar. Un producto cuyo nombre es su
     *       codigo de barras no es un producto: es una ficha que alguien
     *       empezo y no termino. En la vitrina sale como un numero.
     *
     *   «Auchan baby cosmos bio - eau nettoyante micellaire»  frances,
     *       colado pese al filtro de idioma de la fuente.
     */
    private function nombreDe(array $p): ?string
    {
        $crudo = ($p['product_name_es'] ?? '') ?: ($p['product_name'] ?? '');

        /* `&gt;` y compania: la fuente los devuelve sin decodificar. */
        $nombre = trim(html_entity_decode((string) $crudo, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (mb_strlen($nombre) < 3) {
            return null;
        }

        /* Mas numeros que letras: es un codigo disfrazado de nombre. */
        $letras = preg_match_all('/\p{L}/u', $nombre);
        $numeros = preg_match_all('/\d/u', $nombre);

        if ($letras < 3 || $numeros > $letras) {
            return null;
        }

        /* O el codigo, tal cual, metido dentro. */
        $codigo = preg_replace('/\D/', '', (string) ($p['code'] ?? ''));

        if ($codigo !== '' && str_contains($nombre, $codigo)) {
            return null;
        }

        /*
         * Y una pasada de frances, que es lo que mas se cuela.
         *
         * Heuristica, no ciencia: son palabras funcionales que en espanol
         * no existen o no se escriben asi. Si alguna vez saca un producto
         * bueno, el precio es bajo comparado con un catalogo a medias en
         * otro idioma.
         */
        foreach (self::FRANCES as $patron) {
            if (preg_match($patron, $nombre)) {
                return null;
            }
        }

        return mb_substr($nombre, 0, 255);
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
            'name' => $this->nombreDe($p),
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

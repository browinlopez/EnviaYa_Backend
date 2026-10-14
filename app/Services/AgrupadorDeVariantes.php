<?php

namespace App\Services;

/**
 * DECIDIR QUÉ PRODUCTOS SON EL MISMO CON OTRA PRESENTACIÓN.
 *
 * El catálogo no tiene ningún dato que diga que «COCA COLA 1.5» y «COCA COLA
 * 250 ML» son la misma cosa en dos tamaños. Lo único que hay es el nombre, que
 * lo escribió el punto de venta de cada tienda y está escrito de siete
 * maneras. Así que hay que deducirlo, y deducirlo con cuidado: agrupar de más
 * es peor que no agrupar, porque esconde un producto dentro de otro y el
 * cliente termina pidiendo algo que no quería.
 *
 * DOS EJES, QUE SE DETECTAN DISTINTO
 *
 *   TAMAÑO — «SALSA DE TOMATE SAN JORGE *170 GR» / «... 400G»
 *   Se quitan las medidas del nombre y lo que queda es la clave del grupo.
 *   Es el caso más común y el más seguro: un número con unidad no significa
 *   nada por sí mismo, así que quitarlo no puede confundir dos productos.
 *
 *   VARIANTE — «MR TEA DURAZNO» / «MR TEA LIMÓN», «REXONA CLINICAL HOMBRE» /
 *   «... MUJER», «PAÑAL WINNY ETAPA 3» / «ETAPA 4»
 *   Acá la diferencia es una palabra con significado propio, y ahí está el
 *   riesgo. Por eso el vocabulario de sabores y variantes es CERRADO y está
 *   escrito a mano más abajo. Deducirlo de los datos —«las palabras que más
 *   varían dentro de un grupo»— agruparía «ATÚN EN ACEITE» con «ATÚN EN AGUA»,
 *   que son dos productos que nadie confunde en el estante.
 *
 * LAS TRES REGLAS QUE EVITAN AGRUPAR DE MÁS
 *
 *   1. NÚCLEO DE DOS PALABRAS O MÁS. Con una sola, «MORA» —la fruta— acabaría
 *      dentro del grupo de los jugos de mora, y «LIMÓN» dentro de las salsas.
 *   2. MISMA CATEGORÍA. Si los supuestos hermanos están en secciones distintas
 *      es que el parecido de nombres fue casualidad.
 *   3. ETIQUETAS DISTINTAS Y NINGUNA VACÍA. Dos miembros con la misma etiqueta
 *      no son dos variantes: o es un duplicado del catálogo o la etiqueta no
 *      distingue nada, y en los dos casos el chip quedaría repetido.
 *
 * Lo que no pasa las tres reglas se queda sin grupo, que es el estado normal
 * de la mayoría del catálogo y no tiene nada de malo: un producto que se vende
 * en una sola presentación no necesita selector.
 */
class AgrupadorDeVariantes
{
    /** Palabras que no distinguen nada y estorban al comparar. */
    private const VACIAS = [
        'DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS', 'Y', 'CON', 'EN', 'PARA', 'A',
        'AL', 'X', 'UND', 'UNIDAD', 'UNIDADES', 'POR', 'PQ', 'PQTE', 'PAQ',
        'PAQUETE', 'SURTIDO', 'SURTIDA',
    ];

    /**
     * Sabores. Vocabulario CERRADO a propósito: es la lista que puede agrupar
     * de más, así que se escribe a mano y se revisa, no se deduce.
     */
    private const SABORES = [
        'DURAZNO', 'LIMON', 'MORA', 'FRESA', 'NARANJA', 'MANZANA', 'UVA',
        'PERA', 'MANGO', 'MARACUYA', 'PINA', 'GUANABANA', 'TAMARINDO',
        'COROZO', 'SANDIA', 'KIWI', 'CEREZA', 'BANANO', 'COCO', 'VAINILLA',
        'CHOCOLATE', 'AREQUIPE', 'MANI', 'ALMENDRA', 'AVELLANA', 'CAFE',
        'MENTA', 'CANELA', 'MIEL', 'YOGURT', 'TROPICAL', 'JAMAICA', 'PONCHE',
        'MANDARINA', 'PAPAYA', 'LULO', 'GUAYABA', 'BLUEBERRY',
        // Aparecieron al revisar los grupos que NO se juntaron: «GASEOSA
        // POSTOBON KOLA» quedaba fuera del grupo de Postobón y «HIT FRUTAS
        // TROPICALES» fuera del de Hit.
        'KOLA', 'TROPICALES', 'FRUTAS', 'NATURAL', 'SEMIDESCREMADA',
        'DESCREMADA',
    ];

    /** Versiones del mismo producto que no son sabor. */
    private const VARIANTES = [
        'ZERO', 'CERO', 'LIGHT', 'DIET', 'ORIGINAL', 'CLASICA', 'CLASICO',
        'TRADICIONAL', 'HOMBRE', 'MUJER', 'KIDS', 'NINO', 'NINA', 'BEBE',
        'ADULTO', 'FORTE', 'MAX', 'ULTRA', 'PLUS', 'EXTRA', 'FUERTE', 'SUAVE',
        'INTEGRAL', 'ENTERA', 'ENTERO', 'DESLACTOSADA', 'DESLACTOSADO',
        'ETAPA', 'TALLA', 'GRANDE', 'PEQUENA', 'PEQUENO', 'MEDIANA', 'MEDIANO',
        'PERSONAL', 'FAMILIAR', 'JUMBO', 'MINI', 'PREMIUM', 'ESPECIAL',
        'BLANCO', 'BLANCA', 'NEGRO', 'NEGRA', 'ROJA', 'ROJO', 'VERDE', 'AZUL',
        'AMARILLO', 'AMARILLA', 'MORADO', 'MORADA', 'ROSADO', 'ROSADA',
    ];

    /**
     * El envase. Distingue —una cerveza en lata no es la misma compra que en
     * botella— así que va a la ETIQUETA, no al núcleo. Sin esto, «COCA COLA
     * PET 400 ML» y «COCA COLA 3 LT» quedaban en dos grupos distintos por la
     * palabra «PET», y lo mismo «LECHE CONDENSADA TUBITO» con y sin «DOY
     * PACK».
     */
    private const ENVASES = [
        'PET', 'LATA', 'BOTELLA', 'VIDRIO', 'TARRO', 'FRASCO', 'DOYPACK',
        'DOY', 'PACK', 'SOBRE', 'BOLSA', 'CAJA', 'VASO', 'POTE', 'TUBITO',
        'TUBO', 'BARRA', 'SACHET', 'DISPLAY',
    ];

    /**
     * Palabras que dicen a qué sección pertenece el producto, no cuál es.
     *
     * Se quitan del núcleo y NO van a la etiqueta: un chip que dijera
     * «Gaseosa» no ayuda a elegir entre dos gaseosas. Existen porque dos
     * catálogos escriben lo mismo de dos maneras —uno pone «GASEOSA COCA COLA
     * ORIGINAL 1LT» y el otro «COCA COLA 3 LT»— y esa palabra de más partía la
     * Coca-Cola en dos tarjetas.
     */
    private const GENERICAS = [
        'GASEOSA', 'GASEOSAS', 'BEBIDA', 'BEBIDAS', 'PRODUCTO', 'ARTICULO',
        'PRECIO', 'PROMOCION',
    ];

    /**
     * Palabras que nombran una CLASE de producto, no un producto.
     *
     * Un núcleo que se queda en una sola de estas no identifica nada: dos
     * mermeladas de marcas distintas son dos productos, y meterlas en una
     * tarjeta con chips «Fresa» y «Mora» hace creer que son la misma
     * mermelada en dos sabores.
     *
     * El caso que lo destapó fue «AGUA»: juntaba el agua de 5 litros con
     * «AGUA MANZANA», que es una gaseosa de Postobón.
     *
     * Un núcleo de una palabra que NO esté acá sí vale, y es lo normal cuando
     * esa palabra es una marca —HIT, VIVE, POSTOBON, QUATRO, BOKA—: ahí la
     * palabra sola sí identifica al producto. Por eso la lista es de clases y
     * no un mínimo de dos palabras, que descartaba los doce sabores de Hit
     * junto con el agua.
     */
    private const NUCLEOS_DEBILES = [
        'AGUA', 'LECHE', 'PAN', 'QUESO', 'ARROZ', 'ACEITE', 'SALSA', 'JUGO',
        'GALLETA', 'CARNE', 'HUEVO', 'PANELA', 'AZUCAR', 'SAL', 'HARINA',
        'CAFE', 'CHOCOLATE', 'PAPEL', 'JABON', 'CREMA', 'VELA', 'VASO',
        'PLATO', 'MERMELADA', 'VINAGRE', 'COMPOTA', 'YOGUR', 'MANTEQUILLA',
        'MARGARINA', 'ATUN', 'SARDINA', 'AVENA', 'CEREAL', 'SOPA', 'CALDO',
        'HELADO', 'CHICLE', 'CARAMELO', 'PASTA', 'DETERGENTE', 'SHAMPOO',
        'DESODORANTE', 'PANAL', 'TOALLA', 'SERVILLETA', 'BOMBILLO', 'PILA',
        'CUADERNO', 'LAPIZ', 'CINTA', 'ESCOBA', 'CONDENSADA', 'DENTAL',
    ];

    private const UNIDADES = [
        'ML' => 'ml', 'CC' => 'ml', 'LT' => 'L', 'LTS' => 'L', 'L' => 'L',
        'LITRO' => 'L', 'LITROS' => 'L', 'GR' => 'g', 'GRS' => 'g', 'G' => 'g',
        'KG' => 'kg', 'KILO' => 'kg', 'KILOS' => 'kg', 'MG' => 'mg',
        'UND' => 'und', 'UNIDADES' => 'und', 'ONZ' => 'oz', 'OZ' => 'oz',
        'TAB' => 'tabletas', 'TABLETAS' => 'tabletas', 'CAP' => 'cápsulas',
        'CAPSULAS' => 'cápsulas', 'SOBRE' => 'sobres', 'SOBRES' => 'sobres',
        'ROLLO' => 'rollos', 'ROLLOS' => 'rollos', 'HOJAS' => 'hojas',
    ];

    /**
     * Agrupa una colección de productos.
     *
     * Recibe filas con `products_id`, `name`, `brand`, `category_id`, `image`
     * y devuelve los grupos que pasan las tres reglas. No escribe nada.
     */
    public function agrupar(iterable $productos): array
    {
        $porNucleo = [];

        foreach ($productos as $p) {
            $p = (array) $p;
            [$nucleo, $etiqueta] = $this->leer($p['name']);

            if ($nucleo === '' || $this->nucleoDebil($nucleo)) {
                continue;
            }

            $porNucleo[$nucleo][] = $p + ['_etiqueta' => $etiqueta];
        }

        $grupos = [];

        foreach ($porNucleo as $nucleo => $miembros) {
            if (count($miembros) < 2) {
                continue;
            }

            // Regla 2: misma categoría.
            $categorias = array_unique(array_map(fn ($m) => $m['category_id'], $miembros));
            if (count($categorias) > 1) {
                continue;
            }

            // Regla 3: etiquetas distintas y ninguna vacía.
            $etiquetas = array_map(fn ($m) => $m['_etiqueta'], $miembros);
            if (in_array(null, $etiquetas, true) || count(array_unique($etiquetas)) !== count($etiquetas)) {
                continue;
            }

            $grupos[] = [
                'nucleo'      => $nucleo,
                'name'        => $this->nombreDelGrupo($miembros),
                'brand'       => $this->marcaComun($miembros),
                'category_id' => reset($categorias),
                'image'       => $this->primeraImagen($miembros),
                'miembros'    => $miembros,
            ];
        }

        return $grupos;
    }

    /**
     * ¿Este núcleo identifica un producto, o sólo una clase de producto?
     *
     * Sólo se pregunta por los de UNA palabra: con dos o más, lo que se repite
     * ya es específico («SALSA TOMATE SAN JORGE» nombra un producto; «SALSA»
     * sola, no).
     */
    private function nucleoDebil(string $nucleo): bool
    {
        $palabras = explode(' ', $nucleo);

        if (count($palabras) > 1) {
            return false;
        }

        return in_array($palabras[0], self::NUCLEOS_DEBILES, true);
    }

    /** El núcleo (clave del grupo) y la etiqueta de la variante. */
    public function leer(string $nombre): array
    {
        $t = $this->limpiar($nombre);

        $sinMedidas = preg_replace($this->patronMedida(), ' ', $t);
        $sinMedidas = preg_replace('/(?:X|\*)\s*\d+(?!\d)/', ' ', $sinMedidas);
        $sinMedidas = preg_replace('/#\s*\d+/', ' ', $sinMedidas);

        /*
         * Se guarda ANTES de partir la puntuacion porque el numero de una
         * variante se busca aca. Si se busca despues, «ORIGINAL 2.25» ya es
         * «ORIGINAL 2 25» y el «2» pasa por numero de variante: la etiqueta
         * salia «Original 2 2,25 L».
         */
        $conPuntuacion = $sinMedidas;

        $sinMedidas = preg_replace('/[.,#]/', ' ', $sinMedidas);

        $palabras = array_values(array_filter(
            preg_split('/\s+/', trim($sinMedidas)),
            fn ($w) => $w !== '' && !in_array($w, self::VACIAS, true) && !ctype_digit($w)
        ));

        // Las genéricas desaparecen: ni núcleo ni etiqueta.
        $palabras = array_values(array_filter(
            $palabras,
            fn ($w) => !in_array($w, self::GENERICAS, true)
        ));

        $distinguen = array_merge(self::SABORES, self::VARIANTES, self::ENVASES);

        $distintivas = array_values(array_filter(
            $palabras,
            fn ($w) => in_array($w, $distinguen, true)
        ));

        $nucleo = array_values(array_filter(
            $palabras,
            fn ($w) => !in_array($w, $distinguen, true)
        ));

        $nucleo = array_unique($nucleo);
        sort($nucleo);

        $etiqueta = $this->presentacion($nombre);

        if ($distintivas) {
            /*
             * HAY PALABRAS QUE VIENEN CON NUMERO Y EL NUMERO ES LO QUE
             * DISTINGUE. «PAÑAL WINNY ETAPA 3» y «... ETAPA 4» son dos
             * productos, y quedarse con «Etapa» en los dos deja el selector
             * con dos chips iguales —que es justo lo que la regla 3 descarta,
             * asi que el grupo entero se perdia—.
             *
             * El numero se busca en el nombre SIN MEDIDAS, no en el original:
             * en «MR TEA LIMON 1.5 LT» el «1» de la medida se colaba como si
             * fuera el numero del sabor y la etiqueta salia «Limon 1 1,5 L».
             * Sin las medidas solo quedan los numeros que de verdad nombran
             * una variante.
             */
            $texto = implode(' ', array_map(function ($d) use ($conPuntuacion) {
                $palabra = mb_convert_case(mb_strtolower($d), MB_CASE_TITLE, 'UTF-8');

                if (preg_match('/' . preg_quote($d, '/') . '\s+(\d+)(?![.,\d])/', $conPuntuacion, $n)) {
                    return $palabra . ' ' . $n[1];
                }

                return $palabra;
            }, array_unique($distintivas)));

            $etiqueta = $etiqueta === null ? $texto : $texto . ' ' . $etiqueta;
        }

        return [implode(' ', $nucleo), $etiqueta];
    }

    /** «1,5 L», «170 g», «# 10», «x12». Null si el nombre no declara nada. */
    public function presentacion(string $nombre): ?string
    {
        $t = $this->limpiar($nombre);

        if (preg_match($this->patronMedida(), $t, $m)) {
            $numero = str_replace('.', ',', $m[1]);

            return $numero . ' ' . self::UNIDADES[$m[2]];
        }

        if (preg_match('/#\s*(\d+)/', $t, $m)) {
            return '# ' . $m[1];
        }

        /*
         * «COCA COLA 1.5» y «PEPSI 2.5»: en la tienda nadie escribe la unidad
         * porque una gaseosa que dice 1.5 son litros y no hay duda. Sólo se
         * acepta con decimales y al final, para no leer «VELÓN # 2» como dos
         * litros.
         */
        if (preg_match('/(?<![\d.,])(\d[.,]\d{1,2})\s*$/', trim($t), $m)) {
            return str_replace('.', ',', $m[1]) . ' L';
        }

        if (preg_match('/(?:X|\*)\s*(\d+)(?!\d)/', $t, $m)) {
            return 'x' . $m[1];
        }

        return null;
    }

    private function patronMedida(): string
    {
        $unidades = implode('|', array_map(
            'preg_quote',
            array_keys(self::UNIDADES)
        ));

        return '/(?<![A-Z0-9])(\d+(?:[.,]\d+)?)\s*(' . $unidades . ')(?![A-Z])/';
    }

    private function limpiar(string $t): string
    {
        /*
         * LA Ñ, ANTES DE TOCAR NADA MÁS.
         *
         * `iconv` con TRANSLIT convierte «PIÑA» en «PI~NA», y la limpieza de
         * símbolos de dos líneas más abajo convierte ese `~` en un espacio: el
         * resultado era «PI NA», dos palabras de dos letras que no significan
         * nada. «HIT NARANJA PIÑA» daba el núcleo «HIT NA PI» y no agrupaba
         * con nada.
         *
         * Afecta a todo lo que lleva Ñ —piña, pequeña, ñame, pañal, cañita—,
         * que en un catálogo colombiano no es poco. Se cambia a mano antes,
         * que es lo que `iconv` debería hacer y no hace.
         *
         * El carácter de reemplazo va en la misma línea: el archivo del punto
         * de venta llega en otra codificación y la Ñ viene ya rota de origen.
         */
        $t = str_replace(['Ñ', 'ñ', "\u{FFFD}"], ['N', 'n', 'N'], $t);
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t;
        $t = mb_strtoupper($t, 'UTF-8');
        $t = str_replace(['-', '*'], ' ', $t);
        $t = preg_replace('/[^\w\s.,#]/u', ' ', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /**
     * El nombre del grupo: el del miembro más corto, sin nada que distinga a
     * una variante de otra.
     *
     * El más corto es el que menos cosas añade, así que suele ser el que mejor
     * nombra al concepto. Pero no basta con quitarle la medida: «PAÑAL WINNY
     * ETAPA 0» da un grupo llamado «Pañal Winny etapa 0» cuyos chips son las
     * etapas 0 a 5, y «GASEOSA POSTOBON UVA 1.5 LT» da uno llamado «Postobón
     * Uva» que contiene manzana y naranja. El título de la tarjeta quedaba
     * anunciando una de sus variantes como si fuera el todo.
     *
     * Así que se quita también el vocabulario de sabores y variantes, y los
     * números sueltos. Lo que queda es el núcleo, que es justo lo que todos
     * los miembros comparten.
     */
    private function nombreDelGrupo(array $miembros): string
    {
        usort($miembros, fn ($a, $b) => mb_strlen($a['name']) <=> mb_strlen($b['name']));

        $base = $this->limpiarSuave($miembros[0]['name']);

        $base = preg_replace($this->patronMedida(), ' ', $base);
        $base = preg_replace('/(?:X|\*)\s*\d+(?!\d)/i', ' ', $base);
        $base = preg_replace('/#\s*\d+/', ' ', $base);

        /*
         * Las palabras que nombran una variante se quitan comparando su forma
         * sin tildes y en mayúsculas, pero se borra la palabra ORIGINAL del
         * nombre: así «Pañal Winny Etapa 0» pierde «Etapa» conservando las
         * tildes de lo que queda.
         */
        $fuera = array_merge(self::SABORES, self::VARIANTES, self::ENVASES, self::GENERICAS);
        $palabras = preg_split('/\s+/', trim($base));
        $palabras = array_filter($palabras, function ($w) use ($fuera) {
            $plano = mb_strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $w) ?: $w, 'UTF-8');
            $plano = preg_replace('/[^A-Z0-9]/', '', $plano);

            if ($plano === '') {
                return false;
            }

            // Un número suelto sin unidad —«1.5», «400»— tampoco nombra al grupo.
            if (ctype_digit($plano)) {
                return false;
            }

            return !in_array($plano, $fuera, true);
        });

        $base = trim(preg_replace('/\s+/', ' ', implode(' ', $palabras)), " .,-*");

        /*
         * Si al quitarlo todo no queda nada —un producto que se llama sólo por
         * su sabor— se vuelve al nombre entero. Un título feo es mejor que un
         * título vacío.
         */
        return $base !== '' ? mb_substr($base, 0, 255) : mb_substr($miembros[0]['name'], 0, 255);
    }

    /** Como `limpiar` pero conservando tildes y mayúsculas: esto se muestra. */
    private function limpiarSuave(string $t): string
    {
        $t = str_replace("\u{FFFD}", 'Ñ', $t);

        return trim(preg_replace('/\s+/', ' ', str_replace('*', ' ', $t)));
    }

    private function marcaComun(array $miembros): ?string
    {
        $marcas = array_filter(array_map(fn ($m) => $m['brand'] ?? null, $miembros));

        if (!$marcas) {
            return null;
        }

        $unicas = array_unique($marcas);

        // Si no coinciden, no hay marca del grupo: dejarla en null es más
        // honesto que elegir la de uno de los miembros.
        return count($unicas) === 1 ? reset($unicas) : null;
    }

    private function primeraImagen(array $miembros): ?string
    {
        foreach ($miembros as $m) {
            if (!empty($m['image'])) {
                return $m['image'];
            }
        }

        return null;
    }
}

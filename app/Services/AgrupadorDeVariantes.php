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

            // Regla 1: un núcleo de una palabra agrupa de más.
            if (count(explode(' ', $nucleo)) < 2) {
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

        $distintivas = array_values(array_filter(
            $palabras,
            fn ($w) => in_array($w, self::SABORES, true) || in_array($w, self::VARIANTES, true)
        ));

        $nucleo = array_values(array_filter(
            $palabras,
            fn ($w) => !in_array($w, self::SABORES, true) && !in_array($w, self::VARIANTES, true)
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
        // El archivo del punto de venta trae la Ñ partida por la codificación.
        $t = str_replace("\u{FFFD}", 'N', $t);
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t;
        $t = mb_strtoupper($t, 'UTF-8');
        $t = str_replace(['-', '*'], ' ', $t);
        $t = preg_replace('/[^\w\s.,#]/u', ' ', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /**
     * El nombre del grupo: el del miembro más corto, sin su presentación.
     *
     * El más corto es el que menos cosas añade, así que suele ser el que mejor
     * nombra al concepto. Se le quita la presentación porque el grupo no es
     * ninguna de ellas.
     */
    private function nombreDelGrupo(array $miembros): string
    {
        usort($miembros, fn ($a, $b) => mb_strlen($a['name']) <=> mb_strlen($b['name']));

        $base = $miembros[0]['name'];
        $base = preg_replace($this->patronMedida(), ' ', $this->limpiarSuave($base));
        $base = preg_replace('/(?:X|\*)\s*\d+(?!\d)/i', ' ', $base);
        $base = preg_replace('/#\s*\d+/', ' ', $base);
        $base = trim(preg_replace('/\s+/', ' ', $base), " .,-*");

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

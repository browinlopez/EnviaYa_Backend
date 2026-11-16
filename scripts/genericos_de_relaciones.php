<?php

/**
 * Añade el genérico de las relaciones: `@return BelongsTo<User, $this>`.
 *
 * POR QUÉ HACE FALTA. Declarar `: BelongsTo` a secas le dice al analizador que
 * hay una relación, pero no CON QUÉ, así que `$pedido->buyer->user` pasa a ser
 * una propiedad de `Model` y el segundo salto no se comprueba. Medido en su
 * día: tipar sin genéricos subió los avisos de 503 a 564.
 *
 * El modelo relacionado está a la vista en la misma línea —es el primer
 * argumento de `belongsTo(X::class)`—, así que se saca de ahí y no se adivina.
 * Si no está escrito como `::class`, el método se deja igual y se avisa.
 *
 *     php scripts/genericos_de_relaciones.php             # dice qué haría
 *     php scripts/genericos_de_relaciones.php --escribir
 *
 * ============================================================
 * ESTE GUION ESTUVO ROTO Y ERA PELIGROSO. Arreglado el 13 de
 * noviembre de 2026.
 * ============================================================
 *
 * QUÉ HACÍA. Escribía el genérico DENTRO DEL TIPO DE RETORNO:
 *
 *     public function campaign(): BelongsTo<AdCampaign, $this>
 *
 * Y eso no es PHP. Los genéricos de PHPStan viven en el docbloque y en ningún
 * otro sitio; en una firma son un error de sintaxis. Corrido con `--escribir`
 * dejaba **62 modelos sin poder cargarse** —reproducido el 13 de noviembre
 * antes de tocarlo, con `scripts/check_classes_load.php`— o sea la aplicación
 * entera sin arrancar. Se revertía con `git checkout -- app/Models`, pero
 * había que saberlo.
 *
 * POR QUÉ SE QUEDÓ AHÍ ROTO. El commit `a6f993f` lo guardó a propósito, como
 * registro de un camino que no funcionó, «para que nadie vuelva a gastar la
 * tarde en el mismo camino». La intención era buena y el efecto no: un
 * registro no debería poder tumbar la aplicación si alguien lo ejecuta. Y
 * nada lo vigilaba: `LosGuionesArrancanTest` solo comprueba el orden de los
 * `use` en los guiones que arrancan Laravel, y este no lo arranca.
 *
 * LAS TRES COSAS QUE SE ARREGLARON, y las tres se notan al correrlo:
 *
 *  1. El genérico va al DOCBLOQUE, encima del método.
 *  2. SE FUNDE con el docbloque que ya haya en vez de poner otro debajo. PHP
 *     asocia al método solo el último, así que un segundo bloque deja al
 *     primero huérfano y esa explicación escrita a mano es justo lo que no se
 *     puede perder. Es la misma lección que lleva `anotar_modelos.php`.
 *  3. RECONOCE LOS QUE YA LO TIENEN. Antes no, y por eso decía «tendrían
 *     genérico: 149 relaciones» cuando las 153 ya lo llevaban: su cuenta no
 *     era de trabajo pendiente, era de relaciones existentes.
 *
 * HOY DICE 0, y está bien que lo diga: las 153 relaciones del repositorio ya
 * llevan su genérico y hay dos pruebas que lo mantienen así
 * (`LasRelacionesSeConstruyenTest`). Queda por si aparece un modelo nuevo.
 */
$escribir = in_array('--escribir', $argv, true);

/**
 * Qué parámetros lleva el genérico de cada relación.
 *
 * Los de «uno» y los de «muchos» llevan el modelo relacionado y el que
 * declara. `HasManyThrough` y `HasOneThrough` llevan TRES —lo que se alcanza,
 * por dónde se pasa y desde dónde— y `MorphTo` es polimórfica y no sabe a qué
 * apunta: esas tres se dejan y se avisa, porque adivinarlas sería escribir un
 * tipo que puede ser falso.
 */
const FORMA = [
    'BelongsTo' => '<%s, $this>',
    'BelongsToMany' => '<%s, $this>',
    'HasMany' => '<%s, $this>',
    'HasOne' => '<%s, $this>',
    'MorphMany' => '<%s, $this>',
    'MorphOne' => '<%s, $this>',
    'MorphToMany' => '<%s, $this>',
    'HasManyThrough' => null,
    'HasOneThrough' => null,
    'MorphTo' => null,
];

$metodos = 0;
$tocados = 0;
$yaLoTenian = 0;
$aMano = [];

$ficheros = [];
$archivos = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__.'/../app/Models', FilesystemIterator::SKIP_DOTS)
);

foreach ($archivos as $archivo) {
    if ($archivo->getExtension() === 'php') {
        $ficheros[] = $archivo->getPathname();
    }
}

sort($ficheros);

$tipos = implode('|', array_keys(FORMA));

foreach ($ficheros as $ruta) {
    $fuente = file_get_contents($ruta);
    $corto = basename($ruta);

    /*
     * El método con lo que tenga de docbloque encima, su firma ya tipada, y el
     * `return $this->xxx(Clase::class` del cuerpo.
     *
     * Se exige que el `return` sea LO PRIMERO del cuerpo: un método con más de
     * una salida podría devolver otra cosa según el camino, y el nombre de la
     * clase de la primera línea no describiría las demás.
     */
    $patron = '~(/\*\*(?:[^*]|\*(?!/))*\*/\R)?([ \t]*)public function (\w+)\(\)\s*:\s*('.$tipos.')\b'
        .'(\R[ \t]*\{\R[ \t]*return \$this->\w+\(\s*(self|[A-Za-z_][\w\\\\]*)::class)~';

    $nuevo = preg_replace_callback($patron, function ($m) use (
        &$metodos, &$yaLoTenian, &$aMano, $corto, $ruta
    ) {
        [, $doc, $sangria, $nombre, $tipo, $cuerpo, $clase] = $m;
        $doc ??= '';

        /*
         * «¿Ya lo tiene?» VA PRIMERO, y el orden importa: al revés, las tres
         * de tres parámetros salían en la lista de «a mano» aunque su genérico
         * estuviera escrito desde hace días. Un informe que pide trabajo ya
         * hecho se deja de leer.
         */
        if (preg_match('~@return\s+\\\\?\w+\s*<~', $doc)) {
            $yaLoTenian++;

            return $m[0];
        }

        $forma = FORMA[$tipo] ?? null;

        if ($forma === null) {
            $aMano[] = $corto.'::'.$nombre.'()  '.$tipo;

            return $m[0];
        }

        // `self::class` es la clase de este mismo fichero.
        $relacionada = $clase === 'self' ? pathinfo($ruta, PATHINFO_FILENAME) : $clase;
        $corteBarra = strrpos($relacionada, chr(92));

        if ($corteBarra !== false) {
            $relacionada = substr($relacionada, $corteBarra + 1);
        }

        $etiqueta = '@return '.$tipo.sprintf($forma, $relacionada);
        $metodos++;

        if ($doc === '') {
            $docNuevo = $sangria.'/** '.$etiqueta.' */'."\n";
        } else {
            /* DENTRO del bloque que ya hay, antes del cierre: ver punto 2 de
               la cabecera. */
            $cerrado = rtrim($doc);
            $sinCierre = rtrim(substr($cerrado, 0, (int) strrpos($cerrado, '*/')));

            // Una línea en blanco antes de la etiqueta si el bloque lleva prosa.
            $prosa = trim((string) preg_replace('~^\s*/?\*+/?~m', '', $sinCierre)) !== '';
            $hueco = $prosa ? "\n".$sangria.' *' : '';

            $docNuevo = $sinCierre.$hueco."\n".$sangria.' * '.$etiqueta."\n".$sangria.' */'."\n";
        }

        return $docNuevo.$sangria.'public function '.$nombre.'(): '.$tipo.$cuerpo;
    }, $fuente);

    if ($nuevo === null) {
        exit("el patron fallo en {$corto}".PHP_EOL);
    }

    if ($nuevo === $fuente) {
        continue;
    }

    $tocados++;

    if ($escribir) {
        file_put_contents($ruta, $nuevo);
    }
}

echo ($escribir ? 'con genérico' : 'tendrían genérico').": {$metodos} relaciones en {$tocados} modelos".PHP_EOL;
echo 'ya lo tenían: '.$yaLoTenian.PHP_EOL;

if ($aMano !== []) {
    echo PHP_EOL.'a mano ('.count(array_unique($aMano)).'), porque su genérico lleva otra forma:'.PHP_EOL;

    foreach (array_unique($aMano) as $x) {
        echo '  '.$x.PHP_EOL;
    }
}

echo PHP_EOL.'Si escribe, comprueba después que los modelos siguen cargando:'.PHP_EOL;
echo '  php scripts/check_classes_load.php'.PHP_EOL;

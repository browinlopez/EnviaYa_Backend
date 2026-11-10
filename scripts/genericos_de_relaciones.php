<?php

/**
 * Añade el genérico a los tipos de relación: `BelongsTo<User, $this>`.
 *
 * POR QUÉ HACE FALTA ESTE SEGUNDO PASO. Declarar `: BelongsTo` a secas le
 * dice al analizador que hay una relación, pero no CON QUÉ, así que
 * `$pedido->buyer->user` pasa a ser una propiedad de `Model` y el número de
 * avisos SUBE en vez de bajar. Medido: 503 → 564.
 *
 * El modelo relacionado está a la vista en la misma línea —es el primer
 * argumento de `belongsTo(X::class)`—, así que se saca de ahí y no se
 * adivina. Si no está escrito como `::class`, el método se deja igual.
 *
 *     php scripts/genericos_de_relaciones.php             # dice qué haría
 *     php scripts/genericos_de_relaciones.php --escribir
 */
$escribir = in_array('--escribir', $argv, true);

/**
 * Qué parámetros lleva el genérico de cada relación.
 *
 * Los de «uno» y los de «muchos» llevan el modelo relacionado y el que
 * declara; `MorphTo` es polimórfica y no sabe a qué apunta, así que se queda
 * sin genérico.
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
$sinClase = [];

$archivos = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__.'/../app/Models', FilesystemIterator::SKIP_DOTS)
);

foreach ($archivos as $archivo) {
    if ($archivo->getExtension() !== 'php') {
        continue;
    }

    $ruta = $archivo->getPathname();
    $fuente = file_get_contents($ruta);

    // `: Relacion` sin genérico, seguido del cuerpo que nombra el modelo.
    $patron = '~(public function \w+\(\): )([A-Za-z]+)(\s*\{\s*return \$this->\w+\(\s*)([A-Za-z_][\w]*)::class~';

    $nuevo = preg_replace_callback($patron, function ($m) use (&$metodos, &$sinClase) {
        $forma = FORMA[$m[2]] ?? null;

        if ($forma === null) {
            return $m[0];
        }

        $metodos++;

        return $m[1].$m[2].sprintf($forma, $m[4]).$m[3].$m[4].'::class';
    }, $fuente);

    if ($nuevo === $fuente) {
        continue;
    }

    $tocados++;

    if ($escribir) {
        file_put_contents($ruta, $nuevo);
    }
}

echo ($escribir ? 'con genérico' : 'tendrían genérico').": {$metodos} relaciones en {$tocados} modelos".PHP_EOL;

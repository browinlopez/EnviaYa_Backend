<?php

/**
 * Declara el tipo de retorno de los métodos de relación de Eloquent.
 *
 * POR QUÉ, Y NO ES SOLO POR EL ANALIZADOR. `public function buyer()` sin tipo
 * obliga a quien lee a mirar el cuerpo para saber si devuelve uno o muchos, y
 * deja a Larastan sin forma de saber que `$pedido->buyer` existe: de ahí
 * salían 278 de los 503 avisos, todos sobre relaciones y ninguno real.
 *
 * ES SEGURO PORQUE EL TIPO ES CIERTO: solo se toca el método cuyo cuerpo es
 * exactamente `return $this->belongsTo(...)` y compañía. Si devolviera otra
 * cosa en alguna rama, este script no lo reconoce y lo deja como está.
 *
 *     php scripts/tipar_relaciones.php             # dice qué haría
 *     php scripts/tipar_relaciones.php --escribir
 */
$escribir = in_array('--escribir', $argv, true);

/** Cada ayudante de Eloquent y la clase que devuelve. */
const RELACIONES = [
    'belongsTo' => 'BelongsTo',
    'belongsToMany' => 'BelongsToMany',
    'hasMany' => 'HasMany',
    'hasManyThrough' => 'HasManyThrough',
    'hasOne' => 'HasOne',
    'hasOneThrough' => 'HasOneThrough',
    'morphMany' => 'MorphMany',
    'morphOne' => 'MorphOne',
    'morphTo' => 'MorphTo',
    'morphToMany' => 'MorphToMany',
];

$tocados = 0;
$metodos = 0;

$archivos = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__.'/../app/Models', FilesystemIterator::SKIP_DOTS)
);

foreach ($archivos as $archivo) {
    if ($archivo->getExtension() !== 'php') {
        continue;
    }

    $ruta = $archivo->getPathname();
    $fuente = file_get_contents($ruta);
    $nuevo = $fuente;
    $usados = [];

    foreach (RELACIONES as $ayudante => $clase) {
        // Solo el método SIN tipo de retorno cuyo cuerpo empieza por el
        // `return $this-><ayudante>(` correspondiente.
        $patron = '~(public function \w+\(\))(\s*\{\s*(?://[^\n]*\s*|/\*(?:[^*]|\*(?!/))*\*/\s*)*return \$this->'
            .$ayudante.'\()~';

        $nuevo = preg_replace_callback($patron, function ($m) use ($clase, &$usados, &$metodos) {
            $usados[$clase] = true;
            $metodos++;

            return $m[1].': '.$clase.$m[2];
        }, $nuevo);
    }

    if ($nuevo === $fuente) {
        continue;
    }

    // Los `use` que hagan falta, en orden.
    foreach (array_keys($usados) as $clase) {
        $imp = 'use Illuminate\\Database\\Eloquent\\Relations\\'.$clase.';';

        if (str_contains($nuevo, $imp)) {
            continue;
        }

        preg_match_all('~^use [^\n]+;$~m', $nuevo, $todos, PREG_OFFSET_CAPTURE);

        if ($todos[0] === []) {
            continue;
        }

        $destino = null;

        foreach ($todos[0] as [$linea, $pos]) {
            if ($linea > $imp) {
                $destino = [$linea, $pos];
                break;
            }
        }

        if ($destino !== null) {
            $nuevo = substr_replace($nuevo, $imp."\n", $destino[1], 0);
        } else {
            [$ultima, $pos] = end($todos[0]);
            $nuevo = substr_replace($nuevo, "\n".$imp, $pos + strlen($ultima), 0);
        }
    }

    $tocados++;

    if ($escribir) {
        file_put_contents($ruta, $nuevo);
    }
}

echo ($escribir ? 'tipadas' : 'se tiparían').": {$metodos} relaciones en {$tocados} modelos".PHP_EOL;

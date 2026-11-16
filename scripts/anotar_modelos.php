<?php

/**
 * Escribe el bloque `@property` de cada modelo, leyendo el esquema de verdad.
 *
 * POR QUÉ SE ESCRIBIÓ. PHPStan nivel 5 daba 503 avisos y 278 eran
 * `property.notFound`: Larastan no sabe qué columnas tiene un modelo de
 * Eloquent, así que cada `$pedido->total` le parece una propiedad inventada.
 * No son fallos, son ruido — y el ruido es lo que hace que nadie mire la
 * herramienta.
 *
 * ================================================================
 * MEDIDO EL 13-11-2026: HOY NO QUITA NADA. NO LO CORRAS ESPERANDO
 * QUE BAJE EL NÚMERO.
 * ================================================================
 *
 * Se corrió sobre los 70 modelos y se midió el antes y el después sin línea
 * base: **157 -> 161**. Cero avisos quitados y cuatro nuevos. Las 894 líneas
 * que escribe se revirtieron.
 *
 * Las dos razones, y las dos valen para la próxima vez:
 *
 *  1. NI UNO de los 68 `property.notFound` que quedaban era una columna. Eran
 *     relaciones sin tipo de retorno (ya tipadas, las vigila
 *     `LasRelacionesSeConstruyenTest`) y alias de `selectRaw`
 *     --`$pedido->ganado`, `total_orders`--, que una anotación de columnas no
 *     puede tocar. Los 278 del principio se habían ido por otro camino.
 *
 *  2. `@property` declara el tipo de LECTURA y el de ESCRITURA a la vez, y en
 *     Eloquent no coinciden: a una columna `decimal` se le asigna un float y
 *     se lee una cadena. De ahí los cuatro nuevos. Para hacerlo bien harían
 *     falta `@property-read` y `@property-write` por separado, el doble de
 *     líneas, y seguiría sin quitar ninguno de los 60 que quedan.
 *
 * Se deja porque la anotación tiene valor para quien lee el modelo —dice qué
 * columnas hay sin ir a las migraciones— y porque el día que Larastan empiece
 * a mirar las columnas, esto es lo que hará falta. Pero el motivo por el que
 * se escribió ya no se sostiene, y conviene saberlo antes de correrlo.
 *
 * DE DÓNDE SALEN LOS TIPOS. De las migraciones, corridas sobre SQLite en
 * memoria. No de la base de desarrollo: leerla ataría la anotación a lo que
 * tenga puesto quien la corra, y además no hace falta tocarla para nada.
 *
 * SE FUNDE CON EL DOCBLOQUE QUE YA HAY, no se pone otro encima. Estos modelos
 * llevan explicaciones escritas arriba, y un segundo bloque pegado al primero
 * deja al primero huérfano —PHP asocia a la clase solo el último—. Esa
 * explicación es justo lo que no se puede perder.
 *
 *     php scripts/anotar_modelos.php             # dice qué haría
 *     php scripts/anotar_modelos.php --escribir
 */
/*
 * LOS `use` VAN ANTES DEL ARRANQUE. Estaban debajo, y por eso este guion
 * llevaba roto: `Kernel::class` se resolvia como clase global y reventaba con
 * «Class "Kernel" does not exist». `php -l` pasa, porque la sintaxis esta
 * bien, y nada lo llamaba, asi que no se noto.
 *
 * Es la tercera vez con el mismo fallo en el mismo sitio —ya paso en
 * `percentiles_de_latencia.php` y en `openapi.php`, los dos porque Pint
 * acorta el nombre apoyandose en un `use` que esta mas abajo—. Lo vigila
 * ahora `tests/Feature/Contrato/LosGuionesArrancanTest.php`.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
Artisan::call('migrate', ['--force' => true]);

$escribir = in_array('--escribir', $argv, true);

/** El tipo de PHP que corresponde a una columna, mirando también los casts. */
function tipoDe(string $sql, bool $nulo, ?string $cast): string
{
    $carbon = '\\Illuminate\\Support\\Carbon';

    $base = match (true) {
        $cast === 'boolean' => 'bool',
        in_array($cast, ['datetime', 'date', 'immutable_datetime'], true) => $carbon,
        in_array($cast, ['array', 'json', 'collection'], true) => 'array',
        $cast !== null && str_starts_with($cast, 'decimal:') => 'string',
        (bool) preg_match('/^(decimal|float|double|real|numeric)/i', $sql) => 'string',
        (bool) preg_match('/^(int|bigint|smallint|tinyint|mediumint)/i', $sql) => 'int',
        default => 'string',
    };

    return $nulo ? $base.'|null' : $base;
}

$sinTabla = [];
$yaAnotados = 0;
$tocados = 0;
$sinSitio = [];

$archivos = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__.'/../app/Models', FilesystemIterator::SKIP_DOTS)
);

foreach ($archivos as $archivo) {
    if ($archivo->getExtension() !== 'php') {
        continue;
    }

    $ruta = $archivo->getPathname();
    $fuente = file_get_contents($ruta);

    if (! preg_match('/namespace\s+([^;]+);/', $fuente, $ns)
        || ! preg_match('/\bclass\s+(\w+)\s+extends/', $fuente, $cl)) {
        continue;
    }

    $nombre = $cl[1];
    $clase = trim($ns[1]).'\\'.$nombre;

    if (! class_exists($clase)) {
        continue;
    }

    $modelo = new $clase;

    if (! $modelo instanceof Model) {
        continue;
    }

    if (str_contains($fuente, '@property')) {
        $yaAnotados++;

        continue;
    }

    $tabla = $modelo->getTable();

    if (! Schema::hasTable($tabla)) {
        $sinTabla[] = $nombre.' ('.$tabla.')';

        continue;
    }

    $casts = $modelo->getCasts();

    /*
     * `created_at` y `updated_at` NO SALEN EN `getCasts()`. Laravel los
     * convierte por otro camino —`getDates()`—, así que preguntando solo por
     * los casts se anotaban como `string|null`, que es lo que trae el motor
     * pero NO lo que devuelve el modelo.
     *
     * Y eso no es un detalle: con esa anotación, cada
     * `$pedido->created_at->format(...)` del repositorio pasa a ser un error
     * nuevo. El guion estaría CREANDO ruido en vez de quitarlo, que es
     * exactamente lo contrario de para lo que se escribió.
     */
    if ($modelo->usesTimestamps()) {
        foreach ([$modelo->getCreatedAtColumn(), $modelo->getUpdatedAtColumn()] as $columna) {
            if ($columna !== null) {
                $casts[$columna] ??= 'datetime';
            }
        }
    }

    $lineas = [];

    foreach (Schema::getColumns($tabla) as $col) {
        $lineas[] = ' * @property '
            .tipoDe($col['type_name'] ?? 'text', (bool) $col['nullable'], $casts[$col['name']] ?? null)
            .' $'.$col['name'];
    }

    if ($lineas === []) {
        continue;
    }

    $propiedades = implode(PHP_EOL, $lineas);

    // ¿Hay un docbloque justo encima de la clase? Entonces se funde con él.
    $conDoc = '~(/\*\*(?:[^*]|\*(?!/))*)\*/(\s*(?:final\s+)?class\s+'.preg_quote($nombre, '~').'\s+extends)~';
    $sinDoc = '~(^|\R)((?:final\s+)?class\s+'.preg_quote($nombre, '~').'\s+extends)~';

    if (preg_match($conDoc, $fuente)) {
        $nuevo = preg_replace($conDoc, '$1 *'.PHP_EOL.$propiedades.PHP_EOL.' */$2', $fuente, 1);
    } else {
        $bloque = '/**'.PHP_EOL.$propiedades.PHP_EOL.' */'.PHP_EOL;
        $nuevo = preg_replace($sinDoc, '$1'.$bloque.'$2', $fuente, 1);
    }

    if ($nuevo === null || $nuevo === $fuente) {
        $sinSitio[] = $nombre;

        continue;
    }

    $tocados++;

    if ($escribir) {
        file_put_contents($ruta, $nuevo);
    }
}

echo ($escribir ? 'anotados' : 'se anotarían').": {$tocados} modelos".PHP_EOL;
echo 'ya tenían @property: '.$yaAnotados.PHP_EOL;

foreach ([['sin tabla en el esquema', $sinTabla], ['no se encontró dónde escribir', $sinSitio]] as [$rotulo, $lista]) {
    if ($lista !== []) {
        echo $rotulo.' ('.count($lista).'):'.PHP_EOL;
        foreach ($lista as $m) {
            echo '  '.$m.PHP_EOL;
        }
    }
}

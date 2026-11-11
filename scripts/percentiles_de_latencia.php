<?php

/*
 * PERCENTILES POR RUTA, DESDE EL LOG DE LATENCIA.
 *
 *     php scripts/percentiles_de_latencia.php [dias] [--todas]
 *
 * Lee `storage/logs/latencia-*.log` —lo que escribe `MedidaDeLaPeticion`, una
 * línea por petición— y contesta la pregunta de §25 que no se podía contestar:
 * qué endpoints son lentos, cuánto, y si la espera es base de datos o no.
 *
 * Por defecto resume los últimos 7 días (lo que guarda el canal) y enseña las
 * 25 peores. Con `--todas`, todas.
 *
 * LO QUE ESTE INFORME NO DICE, y conviene tenerlo delante antes de decidir
 * nada con él:
 *
 *  - No es el reloj del usuario. Mide desde que la petición entra en el
 *    middleware: queda fuera la red, la cola de nginx y —bajo FPM— el
 *    arranque del framework. Para el tiempo de pared está `$request_time` de
 *    nginx.
 *  - `ms_base` es tiempo de consulta, no de espera por bloqueo ni de conexión.
 *  - Una ruta con pocas muestras sale con los percentiles en blanco a
 *    propósito. Ver `PercentilesDeLatencia::porRuta()`.
 */

/*
 * LOS `use` VAN ARRIBA, Y NO ES ESTILO.
 *
 * Estaban DEBAJO del arranque, y entonces Pint —con
 * `fully_qualified_strict_types`— acorto
 * `Illuminate\Contracts\Console\Kernel::class` a `Kernel::class` apoyandose en
 * un `use` que PHP todavia no habia leido en ese punto del fichero. El
 * resultado: `Target class [Kernel] does not exist` en cada ejecucion.
 *
 * Paso `php -l` sin una queja, que es justo lo que `ESTADO.md` ya advertia
 * —«php -l no basta»— y el motivo de que exista
 * `scripts/check_classes_load.php`. Ese comprobador mira CLASES; un script
 * suelto que no arranca no lo ve nadie salvo al ejecutarlo.
 */

use App\Support\PercentilesDeLatencia;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dias = 7;
$todas = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--todas') {
        $todas = true;
    } elseif (ctype_digit($arg)) {
        $dias = max(1, (int) $arg);
    }
}

$carpeta = storage_path('logs');
$ficheros = [];

for ($i = 0; $i < $dias; $i++) {
    $f = $carpeta.'/latencia-'.date('Y-m-d', strtotime("-{$i} days")).'.log';

    if (is_file($f)) {
        $ficheros[] = $f;
    }
}

echo str_repeat('=', 80), PHP_EOL;
echo 'LATENCIA POR RUTA', PHP_EOL;
echo str_repeat('=', 80), PHP_EOL, PHP_EOL;

echo 'Entorno: ', app()->environment(), PHP_EOL;
echo 'Medida encendida: ', config('rendimiento.medir_peticiones') ? 'si' : 'NO', PHP_EOL;

$muestreo = (float) config('rendimiento.muestreo');
echo 'Muestreo: ', $muestreo >= 1 ? 'todas las peticiones' : ($muestreo * 100).'%', PHP_EOL;
echo 'Ficheros leidos: ', count($ficheros), ' de ', $dias, ' dias', PHP_EOL, PHP_EOL;

if ($ficheros === []) {
    echo 'NO HAY NADA QUE LEER.', PHP_EOL, PHP_EOL;
    echo 'El canal `latencia` no ha escrito ningun fichero en esos dias. Puede ser', PHP_EOL;
    echo 'que la medida este apagada (`MEDIR_PETICIONES`), que no haya habido', PHP_EOL;
    echo 'trafico, o que este no sea el servidor que lo atiende: el log es local a', PHP_EOL;
    echo 'la maquina, no viaja.', PHP_EOL;
    exit(1);
}

$lineas = [];

foreach ($ficheros as $f) {
    $lineas = array_merge($lineas, PercentilesDeLatencia::leer($f));
}

if ($lineas === []) {
    echo 'Los ficheros existen pero no traen ninguna linea legible.', PHP_EOL;
    exit(1);
}

$resumen = PercentilesDeLatencia::porRuta(
    $lineas,
    (int) config('rendimiento.minimo_para_percentil')
);

$todasMs = array_map(fn ($l) => (float) $l['ms'], $lineas);

echo 'EN CONJUNTO  (', count($lineas), ' peticiones, ', count($resumen), ' rutas)', PHP_EOL;
echo str_repeat('-', 80), PHP_EOL;
printf(
    '  p50 %.1f ms   p95 %.1f ms   p99 %.1f ms   max %.1f ms%s',
    PercentilesDeLatencia::percentil($todasMs, 50),
    PercentilesDeLatencia::percentil($todasMs, 95),
    PercentilesDeLatencia::percentil($todasMs, 99),
    max($todasMs),
    PHP_EOL
);

$con500 = count(array_filter($lineas, fn ($l) => (int) $l['estado'] >= 500));
echo '  respuestas 5xx: ', $con500, PHP_EOL, PHP_EOL;

$aEnsenar = $todas ? $resumen : array_slice($resumen, 0, 25);

echo 'POR RUTA', $todas ? '' : '  (las 25 peores por p95)', PHP_EOL;
echo str_repeat('-', 80), PHP_EOL;
printf('  %6s %8s %8s %8s %8s %6s %6s  %s%s', 'n', 'p50', 'p95', 'p99', 'max', 'cons', 'base', 'ruta', PHP_EOL);

foreach ($aEnsenar as $r) {
    $num = fn ($v) => $v === null ? sprintf('%8s', '-') : sprintf('%8.1f', $v);

    printf(
        '  %6d %s %s %s %8.1f %6d %6.1f  %s %s%s',
        $r['n'],
        $num($r['p50']),
        $num($r['p95']),
        $num($r['p99']),
        $r['max'],
        $r['consultas_max'],
        $r['ms_base_mediana'],
        $r['verbo'],
        $r['ruta'],
        PHP_EOL
    );
}

$pocas = count(array_filter($resumen, fn ($r) => $r['p95'] === null));

if ($pocas > 0) {
    echo PHP_EOL, '  ', $pocas, ' rutas salen con los percentiles en blanco: menos de ',
    config('rendimiento.minimo_para_percentil'), ' muestras.', PHP_EOL;
    echo '  Con cinco peticiones el p99 es el maximo con otro nombre, asi que se', PHP_EOL;
    echo '  imprime el maximo y nada mas. La columna `max` si vale para esas.', PHP_EOL;
}

echo PHP_EOL, 'COMO LEER LAS COLUMNAS', PHP_EOL;
echo str_repeat('-', 80), PHP_EOL;
echo '  cons  consultas de la peticion que mas hizo. Si crece con el tamanio de', PHP_EOL;
echo '        la respuesta, es un N+1.', PHP_EOL;
echo '  base  mediana de milisegundos EN LA BASE. Si se parece al p50, la ruta se', PHP_EOL;
echo '        arregla con un indice o con una precarga; si es una fraccion, el', PHP_EOL;
echo '        tiempo se va en otra cosa (serializar, una llamada HTTP, imagenes).', PHP_EOL;

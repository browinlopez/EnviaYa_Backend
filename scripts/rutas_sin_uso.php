<?php

/**
 * ¿LAS LLAMA ALGUIEN DE VERDAD? EL INFORME, LEYENDO EL REGISTRO DEL SERVIDOR.
 *
 * `Documentacion/SUPERFICIE-DE-API-SIN-USO.md` lista 31 rutas que ningún
 * cliente de este repositorio llama. Eso se sacó leyendo código, y leyendo
 * código no se puede llegar más lejos: **la app 1.0.8 está publicada en las
 * dos tiendas y es un binario en teléfonos que no controlamos**. Varias de
 * esas rutas tienen pinta de ser lo que llamaba la versión anterior.
 *
 * Borrar una que la app publicada todavía use no rompe una pantalla del
 * panel: se la rompe a alguien que no puede actualizar hasta que la tienda
 * apruebe la versión nueva. Eso son días.
 *
 * Este script no decide nada. Dice qué llamadas hubo, en qué ventana, y
 * —esto es lo importante— **de qué rutas no puede hablar**.
 *
 *     php scripts/rutas_sin_uso.php                  # el informe
 *     php scripts/rutas_sin_uso.php --detalle        # y todo el registro
 *
 * CÓMO SE LEE UN CERO. Un cero NO es «está muerta». Es «en esta ventana,
 * ningún cliente autenticado la llamó». Tres cosas lo limitan, y las tres
 * salen impresas arriba del informe en vez de quedar en este comentario:
 *
 *   1. LA VENTANA. El canal `audit` es `daily` con `days => 30`: Monolog
 *      conserva los 30 FICHEROS más recientes, no los últimos 30 días. En
 *      un servidor con tráfico diario eso es un mes justo; con tráfico a
 *      ratos alcanza más atrás. La única cifra que vale es la fecha de la
 *      línea más vieja que se encontró, y se imprime.
 *
 *   2. EL ORDEN DEL MIDDLEWARE. `audit.api` va DETRÁS de
 *      `Authenticate:sanctum`, así que una llamada sin token muere en auth
 *      y nunca llega a registrarse. Para esta pregunta basta —la app
 *      publicada llama con token—, pero el registro no cuenta sondeos ni
 *      clientes con la sesión caducada.
 *
 *   3. EL VOLUMEN TOTAL. Si el registro entero tiene cuatro líneas, los
 *      ceros no hablan de las rutas: hablan de que no hay registro.
 *
 * DÓNDE SE CORRE. En el servidor, que es donde está el tráfico real. En una
 * máquina de desarrollo cuenta pruebas y toqueteos, que no sirven para
 * decidir; el informe avisa cuando el entorno no es producción.
 */
require __DIR__.'/../vendor/autoload.php';

use App\Http\Middleware\AuditApiRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$detalle = in_array('--detalle', $argv, true);

/**
 * LAS CANDIDATAS SALEN DE `scripts/rutas-candidatas.json`, que es el único
 * sitio donde están.
 *
 * Estaban escritas aquí dentro, y `rutas_sin_llamante.py` tenía su propia
 * copia. Dos listas de las mismas 31 rutas es tener la lista mal en una de
 * las dos en cuanto alguien retire una: un informe seguiría contando una
 * ruta que ya no existe y el otro se la saltaría, y nadie lo notaría porque
 * los dos imprimen números con buena cara.
 */
$CANDIDATAS = json_decode(file_get_contents(__DIR__.'/rutas-candidatas.json'), true)['grupos']
    ?? throw new RuntimeException('No se pudo leer scripts/rutas-candidatas.json');

/* ------------------------------------------------------------------ */
/* 1 · EL REGISTRO: una pasada, no 31 greps */
/* ------------------------------------------------------------------ */

$ficheros = glob(storage_path('logs/audit-*.log')) ?: [];
sort($ficheros);

/**
 * 'VERBO path' => ['total' => n, 'estados' => [codigo => n], 'ultima' => fecha]
 *
 * Se indexa por verbo Y ruta, no solo por ruta: `v1/product/update` es PUT y
 * `v1/categories-business/update` es POST, y una misma URI puede tener las
 * dos puertas. Mezclarlas haría pasar por viva una que nadie llama.
 */
$porRuta = [];
$lineas = 0;
$deLaSuite = 0;
$desde = null;
$hasta = null;

foreach ($ficheros as $f) {
    $h = fopen($f, 'r');

    if ($h === false) {
        continue;
    }

    while (($l = fgets($h)) !== false) {
        if (! preg_match('~^\[([\d-]+ [\d:]+)\] (\w+)\.~', $l, $fecha)) {
            continue;
        }

        /*
         * LAS LÍNEAS DE LA SUITE NO SON TRÁFICO. En una máquina de
         * desarrollo el mismo fichero mezcla `testing.INFO` —1127 pruebas
         * pegándole a la API— con el toqueteo a mano. Contarlas haría
         * «viva» cualquier ruta que alguna prueba toque, que es lo
         * contrario de lo que se está midiendo.
         */
        if ($fecha[2] === 'testing') {
            $deLaSuite++;

            continue;
        }

        $lineas++;
        $desde ??= $fecha[1];
        $hasta = $fecha[1];

        if (! preg_match('~"path":"([^"]+)"~', $l, $p)) {
            continue;
        }

        preg_match('~"method":"(\w+)"~', $l, $v);
        $clave = ($v[1] ?? '?').' '.$p[1];

        preg_match('~"status":(\d+)~', $l, $s);
        $estado = $s[1] ?? '?';

        /*
         * SE GUARDA DE DÓNDE VINO, y esto no es un adorno del informe.
         *
         * Este script contó seis llamadas sobre tres candidatas y las dio por
         * vivas. Las seis eran de `127.0.0.1`, en dos días, con tres roles
         * distintos y una mezcla de 422, 404 y 200: alguien probando el
         * endpoint a mano, no un cliente. Sin la IP a la vista, un informe
         * dice «se queda» sobre una ruta que lo único que tuvo fue curiosidad.
         *
         * No se descartan solas: detrás de un proxy mal configurado
         * (`TrustProxies`) TODO el tráfico de verdad llega como 127.0.0.1, y
         * un filtro silencioso esconderia justo lo que se busca. Se enseñan y
         * decide quien lee.
         */
        preg_match('~"ip":"([^"]*)"~', $l, $i);

        $porRuta[$clave]['total'] = ($porRuta[$clave]['total'] ?? 0) + 1;
        $porRuta[$clave]['estados'][$estado] = ($porRuta[$clave]['estados'][$estado] ?? 0) + 1;
        $porRuta[$clave]['ips'][$i[1] ?? '?'] = true;
        $porRuta[$clave]['ultima'] = $fecha[1];
    }

    fclose($h);
}

/* ------------------------------------------------------------------ */
/* 2 · EL ROUTER: ¿sigue existiendo, y puede verla el registro? */
/* ------------------------------------------------------------------ */

$enElRouter = [];

foreach (Route::getRoutes() as $r) {
    foreach ($r->methods() as $m) {
        $enElRouter[$m.' '.$r->uri()] = $r->gatherMiddleware();
    }
}

/**
 * Qué puede decir el registro de esta ruta: cadena vacía si puede hablar, o
 * el motivo por el que no.
 *
 * ESTO ES LA MITAD DEL VALOR DEL SCRIPT. Sin esta comprobación, una ruta a
 * la que le falte el middleware saldría con un cero tranquilizador y se
 * retiraría creyendo que nadie la llama, cuando lo que pasa es que nadie la
 * está mirando.
 *
 * SE BUSCAN LAS DOS FORMAS, el alias y la clase, y no es por adornar:
 * `gatherMiddleware()` devuelve lo que está escrito en `routes/api.php` SIN
 * resolver —`audit.api`, no `App\Http\Middleware\AuditApiRequest`—, al
 * contrario que `route:list`, que sí lo resuelve. Buscando solo la clase,
 * este detector daba las 31 por ciegas: estaba ciego él.
 */
function cegueraDe(string $verbo, string $ruta, array $enElRouter): string
{
    $mw = $enElRouter[$verbo.' '.$ruta] ?? null;

    if ($mw === null) {
        return 'YA NO EXISTE en el router: se retiró después de hacerse la lista';
    }

    $pila = implode(' ', $mw);

    if (! str_contains($pila, 'audit.api') && ! str_contains($pila, AuditApiRequest::class)) {
        return 'SIN el middleware de auditoría: un cero aquí no significa nada';
    }

    /*
     * EL REGISTRO NO VE NINGÚN GET, y ya no hay excepciones.
     *
     * Las hubo: nueve lecturas estaban en `WATCHED_READS` mientras se decidía
     * sobre ellas, porque sin eso el informe las habría dado por muertas con
     * un cero que solo significaba «nadie las mira». Las nueve se retiraron
     * el 11 de noviembre de 2026 con las otras 22, así que la lista temporal
     * se fue con la decisión y el filtro de verbo vuelve a ser absoluto.
     */
    if ($verbo === 'GET') {
        return 'es GET: el registro filtra por verbo y no las ve';
    }

    return '';
}

/* ------------------------------------------------------------------ */
/* 3 · EL INFORME */
/* ------------------------------------------------------------------ */

$entorno = app()->environment();
$total = array_sum(array_map('count', $CANDIDATAS));

echo PHP_EOL;
echo "LAS {$total} RUTAS SIN CONSUMIDOR, CONTRA EL REGISTRO DE AUDITORÍA".PHP_EOL;
echo str_repeat('=', 74).PHP_EOL.PHP_EOL;

echo '  entorno          '.$entorno;
echo $entorno === 'production'
    ? PHP_EOL
    : '   <-- no es producción: este tráfico no sirve para decidir'.PHP_EOL;
echo '  ficheros         '.count($ficheros).' (el canal conserva los 30 más recientes)'.PHP_EOL;
echo '  líneas de tráfico '.number_format($lineas).PHP_EOL;

if ($deLaSuite > 0) {
    echo '  descartadas      '.number_format($deLaSuite).' de la suite de pruebas (testing)'.PHP_EOL;
}

echo '  ventana          '.($desde === null ? 'no hay tráfico registrado' : $desde.'  ->  '.$hasta).PHP_EOL;
echo PHP_EOL;

if ($lineas < 500) {
    echo '  !! El registro tiene menos de 500 líneas. Los ceros de abajo hablan'.PHP_EOL;
    echo '     del registro, no de las rutas. Con esto no se decide.'.PHP_EOL.PHP_EOL;
}

$llamadas = 0;
$ciegas = [];
$silenciosas = [];
$vivas = [];

foreach ($CANDIDATAS as $grupo => $rutas) {
    echo str_repeat('-', 74).PHP_EOL;
    echo mb_strtoupper($grupo).PHP_EOL;
    echo str_repeat('-', 74).PHP_EOL;

    foreach ($rutas as [$verbo, $ruta]) {
        $ceguera = cegueraDe($verbo, $ruta, $enElRouter);
        $d = $porRuta[$verbo.' '.$ruta] ?? null;
        $n = $d['total'] ?? 0;

        printf('  %-7s %-46s ', $verbo, $ruta);

        if ($ceguera !== '') {
            echo '?'.PHP_EOL.'          '.$ceguera.PHP_EOL;
            $ciegas[] = "{$verbo} {$ruta}: {$ceguera}";

            continue;
        }

        if ($n === 0) {
            echo 'nadie'.PHP_EOL;
            $silenciosas[] = "{$verbo} {$ruta}";

            continue;
        }

        $llamadas += $n;
        $vivas[] = "{$verbo} {$ruta} ({$n})";
        $estados = [];

        foreach ($d['estados'] as $codigo => $veces) {
            $estados[] = $codigo.'x'.$veces;
        }

        $ips = array_keys($d['ips'] ?? []);
        $deDonde = count($ips) === 1 ? $ips[0] : count($ips).' IPs';

        echo $n.($n === 1 ? ' llamada ' : ' llamadas').'  ['.implode(' ', $estados).']'
            .'  desde '.$deDonde.'  última '.$d['ultima'].PHP_EOL;
    }

    echo PHP_EOL;
}

echo str_repeat('=', 74).PHP_EOL;
echo 'EN LIMPIO'.PHP_EOL.PHP_EOL;
echo '  '.count($silenciosas).' sin una sola llamada en la ventana'.PHP_EOL;
echo '  '.count($vivas).' con llamadas reales: éstas NO se retiran'.PHP_EOL;
echo '  '.count($ciegas).' de las que el registro no puede hablar'.PHP_EOL;
echo '  '.number_format($llamadas).' llamadas contadas sobre las candidatas'.PHP_EOL.PHP_EOL;

if ($vivas !== []) {
    echo 'SE QUEDAN, ESTÁ MEDIDO:'.PHP_EOL;

    foreach ($vivas as $v) {
        echo '  - '.$v.PHP_EOL;
    }

    echo PHP_EOL;
}

if ($ciegas !== []) {
    echo 'SOBRE ÉSTAS NO SE PUEDE DECIDIR, Y HAY QUE ARREGLARLO ANTES:'.PHP_EOL;

    foreach ($ciegas as $c) {
        echo '  - '.$c.PHP_EOL;
    }

    echo PHP_EOL;
}

echo 'Un cero en una ventana de un mes es un argumento, no una prueba: una'.PHP_EOL;
echo 'ruta de uso estacional —algo que se hace al cerrar el mes— puede salir'.PHP_EOL;
echo 'en cero y estar viva. Retirar sigue siendo decisión de producto.'.PHP_EOL.PHP_EOL;

if ($detalle) {
    echo str_repeat('=', 74).PHP_EOL;
    echo 'TODO EL REGISTRO, POR VOLUMEN'.PHP_EOL.PHP_EOL;

    $todas = $porRuta;
    uasort($todas, fn ($a, $b) => $b['total'] <=> $a['total']);

    foreach ($todas as $ruta => $d) {
        printf("  %7d  %s\n", $d['total'], $ruta);
    }

    echo PHP_EOL;
}

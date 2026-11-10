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
 * LAS 31 CANDIDATAS, como las documentó el repositorio el 27 de agosto de
 * 2026. Se repiten aquí a propósito en vez de leer el markdown: la lista es
 * la entrada del informe y tiene que poder revisarse de un vistazo.
 */
const CANDIDATAS = [
    'Reseñas' => [
        ['GET', 'v1/reviews/business/all'],
        ['POST', 'v1/reviews/business/create'],
        ['PUT', 'v1/reviews/business/update'],
        ['DELETE', 'v1/reviews/business/delete'],
        ['GET', 'v1/reviews/domiciliaries/all'],
        ['POST', 'v1/reviews/domiciliary/create'],
        ['PUT', 'v1/reviews/domiciliary/update'],
        ['DELETE', 'v1/reviews/domiciliary/delete'],
        ['GET', 'v1/reviews/users/all'],
        ['GET', 'v1/reviews/user/by'],
        ['POST', 'v1/reviews/user/create'],
        ['PUT', 'v1/reviews/user/update'],
        ['DELETE', 'v1/reviews/user/delete'],
    ],
    'Categorías de negocio' => [
        ['POST', 'v1/categories-business/store'],
        ['POST', 'v1/categories-business/update'],
        ['POST', 'v1/categories-business/destroy'],
        ['POST', 'v1/categories-business/show'],
        ['GET', 'v1/categories/show'],
    ],
    'Domiciliarios' => [
        ['GET', 'v1/domiciliaries/listDomiciliary'],
        ['POST', 'v1/domiciliaries/listbussiness'],
        ['POST', 'v1/domiciliaries/assignToBusiness'],
        ['POST', 'v1/domiciliaries/deleteDomiciliary'],
    ],
    'Productos' => [
        ['POST', 'v1/product/show'],
        ['POST', 'v1/product/top-products'],
        ['PUT', 'v1/product/update'],
    ],
    'Sueltas' => [
        ['GET', 'v1/businesses/affiliations/usersBusinesses'],
        ['GET', 'v1/orders/pending-review'],
        ['GET', 'v1/paymentForms'],
        ['POST', 'v1/chats/create'],
        ['POST', 'v1/users/buyer'],
        ['POST', 'v1/users/show'],
    ],
];

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

        $porRuta[$clave]['total'] = ($porRuta[$clave]['total'] ?? 0) + 1;
        $porRuta[$clave]['estados'][$estado] = ($porRuta[$clave]['estados'][$estado] ?? 0) + 1;
        $porRuta[$clave]['ultima'] = $fecha[1];
    }

    fclose($h);
}

/* ------------------------------------------------------------------ */
/* 2 · EL ROUTER: ¿sigue existiendo, y puede verla el registro? */
/* ------------------------------------------------------------------ */

$vigiladas = AuditApiRequest::lecturasVigiladas();
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
function cegueraDe(string $verbo, string $ruta, array $enElRouter, array $vigiladas): string
{
    $mw = $enElRouter[$verbo.' '.$ruta] ?? null;

    if ($mw === null) {
        return 'YA NO EXISTE en el router: se retiró después de hacerse la lista';
    }

    $pila = implode(' ', $mw);

    if (! str_contains($pila, 'audit.api') && ! str_contains($pila, AuditApiRequest::class)) {
        return 'SIN el middleware de auditoría: un cero aquí no significa nada';
    }

    if ($verbo === 'GET' && ! in_array($ruta, $vigiladas, true)) {
        return 'es GET y no está en WATCHED_READS: el filtro de verbo la descarta';
    }

    return '';
}

/* ------------------------------------------------------------------ */
/* 3 · EL INFORME */
/* ------------------------------------------------------------------ */

$entorno = app()->environment();
$total = array_sum(array_map('count', CANDIDATAS));

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

foreach (CANDIDATAS as $grupo => $rutas) {
    echo str_repeat('-', 74).PHP_EOL;
    echo mb_strtoupper($grupo).PHP_EOL;
    echo str_repeat('-', 74).PHP_EOL;

    foreach ($rutas as [$verbo, $ruta]) {
        $ceguera = cegueraDe($verbo, $ruta, $enElRouter, $vigiladas);
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

        echo $n.($n === 1 ? ' llamada ' : ' llamadas').'  ['.implode(' ', $estados).']  última '.$d['ultima'].PHP_EOL;
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

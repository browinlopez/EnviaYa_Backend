<?php

/**
 * EL REGISTRO DE AUDITORÍA, Y LA FORMA EXACTA DE SUS LÍNEAS.
 *
 * `scripts/rutas_sin_uso.php` decide, leyendo estos ficheros, qué rutas se
 * pueden retirar. Si el registro deja de escribir una ruta —o cambia la
 * forma de la línea— el informe no se cae: sale **en cero**, que se lee
 * como «nadie la llama». Y la siguiente decisión es borrar una ruta que la
 * app publicada sí usa.
 *
 * Por eso estas pruebas fijan las dos cosas: QUÉ se registra y CON QUÉ
 * FORMA. Los dos `preg_match` de abajo son los mismos del informe, a
 * propósito: si alguien cambia el contexto del log, esto se pone rojo antes
 * de que el informe empiece a mentir.
 *
 * Se llama al middleware a mano en vez de pegarle a una ruta, y es por una
 * razón: las nueve vigiladas piden sesión, rol y en algún caso permiso de
 * módulo. Montar todo eso probaría el middleware DE PASO. Acá se prueba el
 * middleware, que es lo que decide.
 */

use App\Http\Middleware\AuditApiRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * El canal `audit` apuntando a un fichero propio, que se lee al final.
 *
 * NOMBRE DISTINTO EN CADA LLAMADA, y no es manía: Monolog deja el descriptor
 * abierto, y en Windows un fichero con descriptor abierto no se puede
 * borrar. Reusando el nombre, la segunda llamada se encontraba un
 * descriptor muerto apuntando a un fichero recién borrado y Monolog moría
 * con «could not be opened in append mode».
 */
function registroDePrueba(): string
{
    $ruta = storage_path('logs/prueba-auditoria-'.uniqid().'.log');

    config([
        'logging.channels.audit.driver' => 'single',
        'logging.channels.audit.path' => $ruta,
    ]);

    /*
     * El canal ya resuelto conserva el manejador viejo y seguiría escribiendo
     * en el fichero de verdad. En logging el método es `forgetChannel`, no
     * `forgetDrivers` —ese es de los managers de caché y difusión— y el
     * nombre equivocado no da un error claro: `LogManager` reenvía lo que no
     * conoce al canal por omisión y termina en «Monolog\Logger no tiene ese
     * método».
     */
    Log::forgetChannel('audit');

    return $ruta;
}

/** Pasa una petición por el middleware y devuelve lo que quedó escrito. */
function loQueSeRegistro(string $verbo, string $ruta, int $estado = 200): string
{
    $fichero = registroDePrueba();

    (new AuditApiRequest)->handle(
        Request::create('/'.$ruta, $verbo),
        fn () => new Response('ok', $estado),
    );

    $contenido = file_exists($fichero) ? file_get_contents($fichero) : '';

    // Primero se suelta el canal —y con él el descriptor—, después se borra.
    Log::forgetChannel('audit');

    if (file_exists($fichero)) {
        @unlink($fichero);
    }

    return $contenido;
}

/* ------------------------------------------------------------------ */
/* QUÉ SE REGISTRA */
/* ------------------------------------------------------------------ */

test('una escritura queda registrada', function () {
    expect(loQueSeRegistro('PUT', 'v1/businesses/update'))->toContain('v1/businesses/update');
});

test('una lectura cualquiera NO queda registrada', function () {
    // El filtro de verbo sigue en pie: sin él, cada GET de la plataforma
    // escribiría una línea y el registro dejaría de servir para nada.
    expect(loQueSeRegistro('GET', 'v1/negocio/pedidos'))->toBe('');
});

test('NINGUNA lectura queda registrada: ya no hay excepciones', function () {
    /*
     * ESTA PRUEBA SUSTITUYE A «las nueve lecturas vigiladas sí quedan
     * registradas», y el cambio es el final de una historia.
     *
     * `WATCHED_READS` eran nueve GET con pase, puestos para poder decidir si
     * se retiraban: sin registro, un cero en el informe solo significaba
     * «nadie las mira». Las nueve se retiraron el 11 de noviembre de 2026
     * con las otras 22 rutas sin llamante, así que la excepción se fue con
     * la decisión que la justificaba.
     *
     * Lo que hay que fijar ahora es lo contrario de antes: que el filtro de
     * verbo volvió a ser absoluto. Se prueba con varias lecturas y de áreas
     * distintas, porque una sola no distingue «no hay excepciones» de «esta
     * no era una de ellas».
     */
    foreach ([
        'v1/negocio/pedidos',
        'v1/admin/overview',
        'v1/businesses/index',
        'v1/domiciliaries/pedidos',
        'v1/conjunto/resumen',
    ] as $ruta) {
        expect(loQueSeRegistro('GET', $ruta))->toBe('');
    }
});

test('la ruta de alto volumen sigue fuera', function () {
    // La posición del domiciliario llega cada pocos segundos por cada pedido
    // en camino. No es una acción de nadie: es un ping.
    expect(loQueSeRegistro('POST', 'v1/orders/geolocation'))->toBe('');
});

/* ------------------------------------------------------------------ */
/* CON QUÉ FORMA — lo que el informe sabe leer */
/* ------------------------------------------------------------------ */

test('la linea tiene la forma que el informe espera', function () {
    $linea = loQueSeRegistro('POST', 'v1/businesses/show', 404);

    // Los tres `preg_match` son los de scripts/rutas_sin_uso.php.
    expect($linea)->toMatch('~^\[[\d-]+ [\d:]+\] \w+\.~');

    preg_match('~"path":"([^"]+)"~', $linea, $p);
    expect($p[1] ?? null)->toBe('v1/businesses/show');

    preg_match('~"method":"(\w+)"~', $linea, $v);
    expect($v[1] ?? null)->toBe('POST');

    preg_match('~"status":(\d+)~', $linea, $s);
    expect($s[1] ?? null)->toBe('404');
});

test('el estado de la respuesta queda tal cual, no solo los exitos', function () {
    /*
     * Importa para el informe: una ruta llamada SIEMPRE con 422 es un
     * cliente confundido, no un consumidor vivo. Sin el estado, las dos
     * cosas se cuentan igual.
     */
    expect(loQueSeRegistro('POST', 'v1/ayuda', 422))->toContain('"status":422');
});

/* ------------------------------------------------------------------ */
/* LA LISTA CLAVADA, Y POR QUE YA NO ESTA */
/* ------------------------------------------------------------------ */

/*
 * Aqui habia una prueba de clavo: «las lecturas vigiladas son exactamente las
 * nueve del documento», que comparaba `WATCHED_READS` con una lista escrita a
 * mano para que no desapareciera una por descuido y con ella la medicion de
 * esa ruta.
 *
 * Decia, con estas palabras: «cuando se retire alguna de verdad, esta lista
 * se actualiza a mano y ahi esta el punto: se actualiza A SABIENDAS». Se
 * retiraron las nueve, el 11 de noviembre de 2026, con las otras 22 rutas sin
 * llamante. La lista no se actualizo: se fue entera, porque la constante que
 * vigilaba tambien se fue.
 *
 * Queda escrito en vez de borrado sin mas porque el mecanismo funciono: se
 * puso con fecha de caducidad y caduco. Si mañana hace falta vigilar otra
 * lectura, se vuelve a poner una lista temporal y otra prueba de clavo igual
 * a esa.
 */

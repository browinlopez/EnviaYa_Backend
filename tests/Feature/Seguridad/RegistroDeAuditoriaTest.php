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
    expect(loQueSeRegistro('PUT', 'v1/product/update'))->toContain('v1/product/update');
});

test('una lectura cualquiera NO queda registrada', function () {
    // El filtro de verbo sigue en pie: sin él, cada GET de la plataforma
    // escribiría una línea y el registro dejaría de servir para nada.
    expect(loQueSeRegistro('GET', 'v1/negocio/pedidos'))->toBe('');
});

test('las nueve lecturas vigiladas si quedan registradas', function () {
    foreach (AuditApiRequest::lecturasVigiladas() as $ruta) {
        expect(loQueSeRegistro('GET', $ruta))
            ->toContain('"method":"GET"')
            ->toContain('"path":"'.$ruta.'"');
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
    $linea = loQueSeRegistro('POST', 'v1/users/show', 404);

    // Los tres `preg_match` son los de scripts/rutas_sin_uso.php.
    expect($linea)->toMatch('~^\[[\d-]+ [\d:]+\] \w+\.~');

    preg_match('~"path":"([^"]+)"~', $linea, $p);
    expect($p[1] ?? null)->toBe('v1/users/show');

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
    expect(loQueSeRegistro('POST', 'v1/chats/create', 422))->toContain('"status":422');
});

/* ------------------------------------------------------------------ */
/* LA LISTA, CLAVADA */
/* ------------------------------------------------------------------ */

test('las lecturas vigiladas son exactamente las nueve del documento', function () {
    /*
     * Prueba de clavo, no de comportamiento. `WATCHED_READS` es temporal: se
     * puso para poder decidir sobre nueve GET y se va con la decisión. Que
     * desaparezca una por descuido —y con ella la medición de esa ruta— es
     * justo el error que no se nota, porque el informe la seguiría contando
     * en cero.
     *
     * Cuando se retire alguna de verdad, esta lista se actualiza a mano y
     * ahí está el punto: se actualiza A SABIENDAS.
     */
    expect(AuditApiRequest::lecturasVigiladas())->toBe([
        'v1/reviews/business/all',
        'v1/reviews/domiciliaries/all',
        'v1/reviews/users/all',
        'v1/reviews/user/by',
        'v1/categories/show',
        'v1/domiciliaries/listDomiciliary',
        'v1/businesses/affiliations/usersBusinesses',
        'v1/orders/pending-review',
        'v1/paymentForms',
    ]);
});

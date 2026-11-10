<?php

/**
 * EL AVISO DE PETICIÓN LENTA, Y QUE NO SE LLEVE NADA DE NADIE.
 *
 * Lo que se vigila aquí no es solo que el aviso salga: es **qué lleva
 * dentro**. §25 pide medir el tiempo acumulado de consultas «sin filtrar
 * secretos», y un log de rendimiento es justo donde se cuela una contraseña
 * o un teléfono sin que nadie lo note: lo escribe el servidor, nadie lo
 * revisa a diario, y acaba en el disco de quien tenga acceso a los logs.
 *
 * Por eso la prueba de ausencia es la importante. La de presencia solo dice
 * que la instrumentación está enchufada.
 */

use App\Models\Rol;
use App\Models\User;
use App\Support\PresupuestoDeConsultas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

/** El canal `rendimiento` apuntando a un fichero propio, y su ruta. */
function registroDeRendimiento(): string
{
    // Nombre distinto en cada llamada: Monolog deja el descriptor abierto y
    // en Windows un fichero con descriptor abierto no se puede borrar.
    $ruta = storage_path('logs/prueba-rendimiento-'.uniqid().'.log');

    config([
        'logging.channels.rendimiento.driver' => 'single',
        'logging.channels.rendimiento.path' => $ruta,
        'logging.channels.rendimiento.level' => 'debug',
    ]);

    Log::forgetChannel('rendimiento');

    return $ruta;
}

/**
 * Deja el contador a cero y el manejador rearmado.
 *
 * HACE FALTA, Y NO ES DETALLE DE PRUEBA. El montaje —crear roles y un usuario
 * con la factoría, que hashea una contraseña— ya consume más de un
 * milisegundo de base de datos. Sin reiniciar, el aviso salta ahí, cuando
 * todavía no hay ninguna petición en curso: la línea sale con «fuera de una
 * ruta» y la prueba mide el montaje en vez del endpoint.
 *
 * Es lo mismo que hace Octane entre peticiones, y la razón de que el aviso
 * funcione una vez por petición y no una vez por trabajador.
 */
function reiniciarElContador(): void
{
    DB::connection()->resetTotalQueryDuration();
    DB::connection()->allowQueryDurationHandlersToRunAgain();
}

/** Hace una petición con la vigilancia puesta y devuelve lo que se escribió. */
function loQueAnotoElPresupuesto(float $presupuestoMs): string
{
    $fichero = registroDeRendimiento();

    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 2]));

    reiniciarElContador();
    PresupuestoDeConsultas::vigilar($presupuestoMs);

    // Cualquier endpoint que consulte sirve: lo que se mide es la suma.
    test()->getJson('/v1/negocio/me');

    $contenido = file_exists($fichero) ? file_get_contents($fichero) : '';

    Log::forgetChannel('rendimiento');

    if (file_exists($fichero)) {
        @unlink($fichero);
    }

    return $contenido;
}

test('pasado el presupuesto, la peticion queda anotada', function () {
    $linea = loQueAnotoElPresupuesto(0.01);

    expect($linea)
        ->toContain('presupuesto de consultas excedido')
        ->toContain('"ruta":"v1/negocio/me"')
        ->toContain('"presupuesto_ms":0.01');
});

test('con el presupuesto en cero no se anota nada', function () {
    /*
     * Es la forma de apagarlo desde el entorno —`DB_PRESUPUESTO_MS=0`— sin
     * tocar código ni desplegar. Si esto no funcionara, la única manera de
     * callar un aviso ruidoso en producción sería un despliegue.
     */
    expect(loQueAnotoElPresupuesto(0))->toBe('');
});

test('el aviso NO lleva la SQL ni los parametros', function () {
    /*
     * LA PRUEBA QUE IMPORTA.
     *
     * `QueryExecuted` trae la sentencia y sus `bindings`, y escribirlos es lo
     * primero que se le ocurre a cualquiera cuando quiere depurar algo. En
     * esta API eso significa el correo de un tendero, el teléfono de un
     * comprador, la dirección de una casa o el `remember_token` de una
     * sesión, en un fichero de texto que nadie vigila.
     *
     * La forma segura de enmascarar un parámetro es no escribirlo. Con la
     * ruta y el total ya se sabe dónde mirar, y para ver la consulta se mira
     * el código.
     */
    $linea = loQueAnotoElPresupuesto(0.01);

    expect($linea)
        ->not->toContain('select ')
        ->not->toContain('bindings')
        ->not->toContain('@example')      // los correos de la factoría
        ->not->toContain('password')
        ->not->toContain('remember_token');
});

test('anota la ruta con su marcador, no el id de nadie', function () {
    /*
     * `v1/negocio/pedidos/{id}/cancelar` y no `v1/negocio/pedidos/8814/...`.
     * Dos razones: el identificador no aporta —lo que se busca es la ruta
     * lenta, no la petición concreta— y un identificador en un log es un dato
     * de alguien.
     */
    $fichero = registroDeRendimiento();

    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 2]));

    reiniciarElContador();
    PresupuestoDeConsultas::vigilar(0.01);

    // Una ruta CON parámetro: `/negocio/domiciliarios/{id}`.
    test()->getJson('/v1/negocio/domiciliarios/9999');

    $linea = file_exists($fichero) ? file_get_contents($fichero) : '';
    Log::forgetChannel('rendimiento');
    @unlink($fichero);

    expect($linea)->toContain('{id}');
    expect($linea)->not->toContain('9999');
});

test('escribe los dos numeros con los que se interpreta el aviso', function () {
    /*
     * LOS DOS, Y EN ESTE ORDEN, porque juntos dicen QUE arreglar:
     *
     *   ms_al_cruzar   el acumulado en el instante de cruzar
     *   cruzo_con_ms   lo que tardo la consulta que lo cruzo
     *
     * Si la segunda se parece al presupuesto, lo que hubo fue UNA consulta
     * lenta y falta un indice. Si es mucho menor, fue la suma de muchas
     * rapidas, que es la firma de un N+1. Sin los dos campos no se distingue,
     * y es la distincion que §25 pide no confundir.
     *
     * SE COMPRUEBA LA INVARIANTE, no la proporcion: el acumulado INCLUYE la
     * consulta que cruzo, asi que nunca puede ser menor. Eso es cierto con
     * cualquier dato, y es lo que se rompe si alguien intercambia los dos
     * campos al tocar el log — que es el error facil, porque la aplicacion
     * seguiria funcionando igual.
     *
     * La proporcion NO se prueba: en estas pruebas el presupuesto tiene que
     * ser de 0,01 ms para que se dispare, y a ese tamaño una sola consulta de
     * SQLite ya lo cruza diez veces. El entorno no puede producir el caso de
     * «la suma de muchas», asi que no se finge que se comprueba.
     */
    $linea = loQueAnotoElPresupuesto(0.01);

    preg_match('~"ms_al_cruzar":([\d.]+)~', $linea, $acumulado);
    preg_match('~"cruzo_con_ms":([\d.]+)~', $linea, $cruzo);

    expect($acumulado[1] ?? null)->not->toBeNull();
    expect($cruzo[1] ?? null)->not->toBeNull();
    expect((float) $acumulado[1])->toBeGreaterThanOrEqual((float) $cruzo[1]);
});

afterEach(function () {
    // Que el presupuesto de las pruebas no se quede puesto para las demás.
    DB::connection()->allowQueryDurationHandlersToRunAgain();
});

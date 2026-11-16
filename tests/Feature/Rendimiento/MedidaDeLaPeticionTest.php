<?php

use App\Models\Rol;
use App\Models\User;
use App\Support\PercentilesDeLatencia;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

/**
 * LA MEDIDA DE CADA PETICIÓN
 *
 * §25 pide percentiles por endpoint y no había duraciones que resumir.
 * `PresupuestoDeConsultas` avisa de quien se pasa de tiempo en la base, y su
 * propio comentario dice lo que no puede decir: cuánto tardó la petición. El
 * manejador salta al cruzar el presupuesto, así que el acumulado en ese
 * instante vale siempre ≈ el presupuesto.
 *
 * Lo que se fija aquí es lo que hace que la medida sirva y no estorbe: que
 * escriba el PATRÓN de la ruta y no la URI con ids, que no escriba ni SQL ni
 * parámetros ni correos, que se pueda apagar desde el entorno, que el contador
 * de consultas no herede el de la petición anterior —que es el fallo que
 * tendría bajo Octane— y que el cálculo del percentil no invente números
 * cuando hay cuatro muestras.
 */
function registroDeLatencia(): string
{
    // `uniqid` porque en Windows Monolog deja el descriptor abierto y reusar
    // el nombre da «could not be opened in append mode».
    $ruta = storage_path('logs/prueba-latencia-'.uniqid().'.log');

    config([
        'rendimiento.medir_peticiones' => true,
        'rendimiento.muestreo' => 1.0,
        'logging.channels.latencia.driver' => 'single',
        'logging.channels.latencia.path' => $ruta,
        'logging.channels.latencia.level' => 'info',
    ]);

    Log::forgetChannel('latencia');

    return $ruta;
}

function lineasDeLatencia(string $ruta): array
{
    Log::forgetChannel('latencia');

    return is_file($ruta) ? PercentilesDeLatencia::leer($ruta) : [];
}

it('anota una linea por peticion con la ruta, el verbo y el estado', function () {
    $log = registroDeLatencia();

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $this->getJson('/v1/paymentMethods')->assertOk();

    $lineas = lineasDeLatencia($log);

    expect($lineas)->toHaveCount(1);
    expect($lineas[0]['ruta'])->toBe('v1/paymentMethods');
    expect($lineas[0]['verbo'])->toBe('GET');
    expect($lineas[0]['estado'])->toBe(200);
    expect($lineas[0]['ms'])->toBeGreaterThan(0);
    expect($lineas[0])->toHaveKeys(['consultas', 'ms_base']);

    @unlink($log);
});

it('escribe el patron de la ruta y nunca el id que venia en la URI', function () {
    $log = registroDeLatencia();

    /*
     * Una ruta QUE EXISTE y lleva marcador, con un id que se reconoce a
     * simple vista en el fichero si alguna vez se escribiera. Se pide SIN
     * sesión a propósito: contesta 401, y lo que se comprueba es lo que queda
     * escrito, no lo que contesta. El middleware es global, así que envuelve
     * al enrutador: cuando lee `route()`, el emparejamiento ya ocurrió aunque
     * la respuesta sea un rechazo.
     */
    $this->getJson('/v1/admin/orders/987654321')->assertUnauthorized();

    expect(file_get_contents($log))->not->toContain('987654321');
    expect(lineasDeLatencia($log)[0]['ruta'])->toBe('v1/admin/orders/{id}');

    @unlink($log);
});

it('dice «sin ruta» cuando la URI no empareja con ninguna', function () {
    $log = registroDeLatencia();

    /*
     * ESTO NO ES UN DESCUIDO, Y CONVIENE SABERLO ANTES DE LEER EL INFORME.
     *
     * Si la URI no empareja, no hay patrón que escribir: `route()` vale null
     * y todas las peticiones a rutas inexistentes caen en un mismo grupo
     * llamado «sin ruta». Lo ÚTIL de que caigan juntas es que ese grupo
     * creciendo es la señal de que una app publicada está pidiendo algo que
     * el backend ya no sirve. Lo importante es que el grupo NO lleve la URI,
     * porque una URI inventada por un cliente es justo donde aparecen tokens
     * y correos pegados a mano.
     */
    $this->getJson('/v1/esto-no-existe/abc123')->assertNotFound();

    $lineas = lineasDeLatencia($log);

    expect($lineas[0]['ruta'])->toBe('sin ruta');
    expect(file_get_contents($log))->not->toContain('abc123');

    @unlink($log);
});

it('no escribe SQL, ni parametros, ni correos, ni quien pidio', function () {
    $log = registroDeLatencia();

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $user = User::factory()->create(['rol' => 1, 'email' => 'comprador@ejemplo.test']);
    Sanctum::actingAs($user);

    $this->getJson('/v1/user/profile');

    expect(file_get_contents($log))
        ->not->toContain('select ')
        ->not->toContain('bindings')
        ->not->toContain('comprador@ejemplo.test')
        ->not->toContain('password')
        ->not->toContain('remember_token');

    @unlink($log);
});

it('cuenta las consultas de ESTA peticion, y cuenta alguna', function () {
    $log = registroDeLatencia();

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    /*
     * DOS AFIRMACIONES, Y LA PRIMERA NACIÓ DE UN FALLO MÍO.
     *
     * La versión original solo comprobaba que las dos peticiones contaran lo
     * MISMO —la invariante que se rompe si el contador no se reinicia entre
     * peticiones, que es lo que pasaría bajo Octane—. Pasaba en verde contra
     * un contador que no contaba nada: las dos valían cero, y cero es igual a
     * cero. El cero venía de un centinela estático que daba el registro del
     * oyente por hecho después de que la aplicación se reconstruyera (ver
     * `ContadorDeConsultas`).
     *
     * Por eso ahora se exige que cuente ALGO y además que las dos coincidan.
     * Una igualdad entre dos ceros no es una medida, es un silencio.
     *
     * No se afirma un número concreto de consultas: eso cambiaría con
     * cualquier precarga y haría roja una mejora.
     */
    $this->getJson('/v1/paymentMethods')->assertOk();
    $this->getJson('/v1/paymentMethods')->assertOk();

    $lineas = lineasDeLatencia($log);

    expect($lineas)->toHaveCount(2);
    expect($lineas[0]['consultas'])->toBeGreaterThan(0);
    expect($lineas[1]['consultas'])->toBe($lineas[0]['consultas']);

    // Lo mismo con el tiempo de base: si cuenta consultas, tuvo que gastar
    // tiempo en ellas. Un `ms_base` en cero con consultas a la vista sería
    // otro contador muerto, el de la conexión.
    expect($lineas[0]['ms_base'])->toBeGreaterThan(0);

    @unlink($log);
});

it('no anota nada con la medida apagada ni con el muestreo en cero', function () {
    $log = registroDeLatencia();
    config(['rendimiento.medir_peticiones' => false]);

    $this->getJson('/v1/paymentMethods');

    expect(lineasDeLatencia($log))->toBe([]);

    config(['rendimiento.medir_peticiones' => true, 'rendimiento.muestreo' => 0.0]);

    $this->getJson('/v1/paymentMethods');

    expect(lineasDeLatencia($log))->toBe([]);

    @unlink($log);
});

it('calcula el percentil por rango, sin interpolar y sin salirse del array', function () {
    $diez = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];

    // ceil(0.50 * 10) = 5 → la quinta de la lista ordenada.
    expect(PercentilesDeLatencia::percentil($diez, 50))->toBe(5.0);
    expect(PercentilesDeLatencia::percentil($diez, 95))->toBe(10.0);
    expect(PercentilesDeLatencia::percentil($diez, 100))->toBe(10.0);

    // El borde que rompe la fórmula ingenua: ceil(0) - 1 = -1.
    expect(PercentilesDeLatencia::percentil($diez, 0))->toBe(1.0);

    // Desordenadas, y con una sola muestra.
    expect(PercentilesDeLatencia::percentil([9.0, 1.0, 5.0], 50))->toBe(5.0);
    expect(PercentilesDeLatencia::percentil([42.0], 99))->toBe(42.0);

    expect(fn () => PercentilesDeLatencia::percentil([], 50))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => PercentilesDeLatencia::percentil($diez, 101))
        ->toThrow(InvalidArgumentException::class);
});

it('deja en blanco los percentiles de una ruta con pocas muestras', function () {
    $lineas = [];

    for ($i = 1; $i <= 30; $i++) {
        $lineas[] = ['ruta' => 'v1/mucha', 'verbo' => 'GET', 'estado' => 200,
            'ms' => (float) $i, 'consultas' => 3, 'ms_base' => 1.0];
    }

    for ($i = 1; $i <= 4; $i++) {
        $lineas[] = ['ruta' => 'v1/poca', 'verbo' => 'GET', 'estado' => 200,
            'ms' => 900.0, 'consultas' => 50, 'ms_base' => 800.0];
    }

    $resumen = collect(PercentilesDeLatencia::porRuta($lineas, 20))->keyBy('ruta');

    expect($resumen['v1/mucha']['n'])->toBe(30);
    expect($resumen['v1/mucha']['p95'])->not->toBeNull();

    /*
     * La de cuatro muestras es la MÁS lenta de las dos —900 ms contra 30— y
     * aun así sale sin percentiles. Es a propósito: con cuatro peticiones el
     * p50, el p95 y el p99 serían el mismo 900, tres nombres para un dato que
     * no tiene distribución. Lo que sí se imprime es el máximo.
     */
    expect($resumen['v1/poca']['p50'])->toBeNull();
    expect($resumen['v1/poca']['p99'])->toBeNull();
    expect($resumen['v1/poca']['max'])->toBe(900.0);

    // Y queda ordenada DETRÁS de la que sí se sabe medir, aunque sea peor.
    expect(array_column(PercentilesDeLatencia::porRuta($lineas, 20), 'ruta'))
        ->toBe(['v1/mucha', 'v1/poca']);
});

it('se salta una linea cortada por la rotacion sin tumbar el informe', function () {
    $ruta = storage_path('logs/prueba-latencia-roto-'.uniqid().'.log');

    file_put_contents($ruta, implode("\n", [
        '[2026-11-10 09:00:00] production.INFO: peticion {"ruta":"v1/a","verbo":"GET","estado":200,"ms":10,"consultas":2,"ms_base":1}',
        // Cortada a mitad de escritura, que es lo que deja la rotación.
        '[2026-11-10 09:00:01] production.INFO: peticion {"ruta":"v1/b","verbo":"GE',
        // De otra cosa, en el mismo fichero.
        '[2026-11-10 09:00:02] production.ERROR: algo sin JSON',
        '[2026-11-10 09:00:03] production.INFO: peticion {"ruta":"v1/c","verbo":"GET","estado":500,"ms":20,"consultas":1,"ms_base":2}',
        '',
    ]));

    $lineas = PercentilesDeLatencia::leer($ruta);

    expect($lineas)->toHaveCount(2);
    expect(array_column($lineas, 'ruta'))->toBe(['v1/a', 'v1/c']);

    @unlink($ruta);
});

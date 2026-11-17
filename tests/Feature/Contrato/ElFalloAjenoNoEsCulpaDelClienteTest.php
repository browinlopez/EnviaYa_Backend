<?php

use App\Models\Area;
use App\Models\Domiciliary;
use App\Models\Rol;
use App\Models\User;
use App\Services\BusinessMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * UN FALLO QUE NO ES DEL CLIENTE NO SE LE CUENTA AL CLIENTE.
 *
 * Había nueve `catch (\Throwable)` en `app/` que metían
 * `$e->getMessage()` DENTRO de la respuesta, ocho de ellos con un 422. Dos
 * cosas mal en una:
 *
 *  1. **Un 422 afirma que el cliente se equivocó.** Con `\Throwable` en el
 *     `catch`, el disco lleno, R2 sin responder y la base caída salían como
 *     «lo que mandaste está mal»: el teléfono pedía corregir una foto que
 *     estaba perfecta, y quien la mandaba reintentaba contra una avería.
 *  2. **La avería no llegaba a Sentry**, porque la excepción moría en el
 *     `catch` y no subía al manejador. Nadie se enteraba.
 *
 * El arreglo no fue quitar los `catch`: los servicios de este repositorio
 * --`MediaService`, `BusinessMediaService`, `ContratoService`-- lanzan SOLO
 * `RuntimeException`, y siempre con un texto escrito para quien lo va a leer
 * («El archivo supera los 8 MB permitidos.»). Se atrapa esa, que sí es un 422
 * legítimo, y lo demás sube.
 *
 * LO QUE ESTE FICHERO DEFIENDE, en este orden:
 *
 *  - Que no vuelva a aparecer el décimo. Es la prueba que de verdad importa:
 *    las de abajo cubren dos endpoints, esta cubre `app/` entero.
 *  - Que el 422 legítimo siga saliendo, con su mensaje. Estrechar el `catch`
 *    sin esto podría haber dejado al tendero sin saber por qué falla su logo.
 *  - Que donde el `catch` está para revertir una transacción, la reversión
 *    siga ocurriendo. Es el riesgo real de cambiarlo por un `throw`.
 */

/**
 * Los `catch` de un texto PHP que ponen el mensaje de la excepción DENTRO de
 * la respuesta.
 *
 * MIRA LA RESPUESTA, NO EL BLOQUE. La primera versión de esta medición contó
 * mal justo por eso: marcaba el bloque si contenía `getMessage()` y
 * `response()->json` en cualquier sitio, y así contaba como fuga a
 * `BancosPseController`, donde el mensaje va al REGISTRO
 * --`Log::error(..., ['excepcion' => $e->getMessage()])`-- y la respuesta
 * lleva un texto escrito. Son lo contrario: uno deja rastro para quien
 * depura, el otro se lo enseña al cliente. Dio 14 en vez de 9.
 *
 * Así que se extrae el argumento de cada `response()->json(...)` contando
 * paréntesis, y solo cuenta si `getMessage()` aparece ahí dentro.
 */
function fugasDeExcepcion(string $codigo): array
{
    $cerrar = function (string $t, int $i, string $abre, string $cierra): string {
        $nivel = 0;
        for ($j = $i, $n = strlen($t); $j < $n; $j++) {
            if ($t[$j] === $abre) {
                $nivel++;
            } elseif ($t[$j] === $cierra) {
                $nivel--;
                if ($nivel === 0) {
                    return substr($t, $i, $j - $i + 1);
                }
            }
        }

        return '';
    };

    $fuera = [];

    preg_match_all('/catch \([^)]*Throwable[^)]*\)\s*\{/', $codigo, $_, PREG_OFFSET_CAPTURE);

    foreach ($_[0] as [$texto, $donde]) {
        $abre = strpos($codigo, '{', $donde + strlen($texto) - 1);
        if ($abre === false) {
            continue;
        }

        $bloque = $cerrar($codigo, $abre, '{', '}');

        preg_match_all('/response\(\)\s*->\s*json\s*\(/', $bloque, $jsons, PREG_OFFSET_CAPTURE);

        foreach ($jsons[0] as [$t2, $d2]) {
            $argumentos = $cerrar($bloque, $d2 + strlen($t2) - 1, '(', ')');

            if (str_contains($argumentos, 'getMessage()')) {
                $fuera[] = substr_count(substr($codigo, 0, $donde), "\n") + 1;
                break;
            }
        }
    }

    return $fuera;
}

it('ningun catch de app/ manda el mensaje de la excepcion al cliente', function () {
    /*
     * PASADA DE CONTROL, Y AQUÍ NO ES ADORNO. Un detector que no encuentra lo
     * que se sabe que hay pasa en verde estando ciego, y esta prueba afirma
     * una AUSENCIA: si el detector se rompe --un `preg_match_all` que deja de
     * casar, un conteo de paréntesis mal-- la lista sale vacía y la prueba
     * felicita a todo el mundo para siempre.
     *
     * Así que primero se le dan dos trozos de código con la respuesta ya
     * sabida: uno que es fuga y otro que se le parece pero no lo es.
     */
    $conFuga = <<<'PHP'
        try {
            $x = algo();
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        PHP;

    expect(fugasDeExcepcion($conFuga))->toHaveCount(1, 'El detector no ve una fuga evidente');

    $sinFuga = <<<'PHP'
        try {
            $x = algo();
        } catch (\Throwable $e) {
            Log::error('No se pudo', ['excepcion' => $e->getMessage()]);

            return response()->json(['message' => 'No pudimos ahora mismo. Intenta luego.'], 503);
        }
        PHP;

    expect(fugasDeExcepcion($sinFuga))->toBe([], 'El detector confunde el registro con la respuesta');

    // Y ahora `app/` entero.
    $fugas = [];

    $ficheros = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($ficheros as $fichero) {
        if ($fichero->isDir() || $fichero->getExtension() !== 'php') {
            continue;
        }

        $relativo = str_replace('\\', '/', substr($fichero->getPathname(), strlen(base_path()) + 1));

        foreach (fugasDeExcepcion((string) file_get_contents($fichero->getPathname())) as $linea) {
            $fugas[] = $relativo.':'.$linea;
        }
    }

    sort($fugas);

    /*
     * Se junta la lista y se compara con la vacía, en vez de un `toBeEmpty()`:
     * así el fallo NOMBRA el fichero y la línea del que se cuela, que es lo
     * único que hace falta para arreglarlo.
     */
    expect($fugas)->toBe([]);
});

it('el fallo que el servicio SI anuncia sigue saliendo como 422, con su mensaje', function () {
    /*
     * El riesgo de estrechar el `catch` era este: dejar al tendero sin saber
     * por qué no entra su logo. `BusinessMediaService` lanza
     * `RuntimeException` con el motivo, y eso tiene que seguir llegándole.
     */
    config([
        'filesystems.disks.r2.key' => 'prueba',
        'filesystems.disks.r2.secret' => 'prueba',
        'filesystems.disks.r2.endpoint' => 'https://r2.prueba',
        'filesystems.disks.r2.url' => 'https://archivos.prueba',
    ]);
    Storage::fake('r2');

    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    $medios = Mockery::mock(BusinessMediaService::class);
    $medios->shouldReceive('configurado')->andReturn(true);
    $medios->shouldReceive('subir')
        ->andThrow(new RuntimeException('El archivo supera los 8 MB permitidos.'));
    app()->instance(BusinessMediaService::class, $medios);

    $this->post('/v1/negocio/me/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'No se pudo guardar el logo. El archivo supera los 8 MB permitidos.');
});

it('un fallo que el servicio NO anuncia ya no sale como 422: sube', function () {
    config([
        'filesystems.disks.r2.key' => 'prueba',
        'filesystems.disks.r2.secret' => 'prueba',
        'filesystems.disks.r2.endpoint' => 'https://r2.prueba',
        'filesystems.disks.r2.url' => 'https://archivos.prueba',
    ]);
    Storage::fake('r2');

    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    /*
     * `LogicException` no es `RuntimeException`, así que representa lo que el
     * servicio NO lanza a propósito: un error de programación, o una avería
     * envuelta por alguna capa de abajo. Antes salía como 422; ahora sube al
     * manejador --que es lo que la pone en Sentry-- y `throws` lo comprueba.
     */
    $medios = Mockery::mock(BusinessMediaService::class);
    $medios->shouldReceive('configurado')->andReturn(true);
    $medios->shouldReceive('subir')->andThrow(new LogicException('averia de prueba'));
    app()->instance(BusinessMediaService::class, $medios);

    $this->withoutExceptionHandling()->post('/v1/negocio/me/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
    ], ['Accept' => 'application/json']);
})->throws(LogicException::class, 'averia de prueba');

it('dar de alta un repartidor sin mandar state no revienta, y queda activo', function () {
    /*
     * POR QUE ESTA PRUEBA. `CrearDomiciliarioRequest` declara `state` como
     * opcional, y el controlador lo escribia tal cual: `null`. La migracion
     * `estados_sin_null_y_pivotes_unicos` deja `user.state` en
     * `TINYINT NOT NULL DEFAULT 1` **solo si el motor es mysql**, asi que esto
     * era un 500 en produccion y verde en la suite. Lo encontro el CI.
     *
     * Se afirma el valor guardado y no solo el 201: sobre SQLite la columna
     * sigue admitiendo null, asi que un `assertCreated()` a secas pasaria sin
     * comprobar nada. Asi la prueba vale en los dos motores.
     */
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    Sanctum::actingAs(User::factory()->create([
        'rol' => 4,
        'area_id' => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => 'gestor',
    ]));

    $this->postJson('/v1/domiciliaries/createDomiciliary', [
        'name' => 'Repartidor Sin Estado',
        'email' => 'sin.estado@prueba.test',
        'password' => 'secreta123',
    ])->assertOk();

    $usuario = DB::table('user')->where('email', 'sin.estado@prueba.test')->first();

    expect($usuario)->not->toBeNull()
        ->and((int) $usuario->state)->toBe(1)
        ->and((int) DB::table('domiciliary')->where('user_id', $usuario->user_id)->value('state'))
        ->toBe(1);
});

it('el alta de domiciliario sigue revirtiendo cuando algo se rompe por dentro', function () {
    /*
     * ESTE `catch` SE QUEDÓ ATRAPANDO `\Throwable`, porque está para el
     * `rollBack()`. Lo que cambió es que ya no devuelve un 500 con el texto de
     * la excepción: revierte y vuelve a lanzar.
     *
     * Y el riesgo de ese cambio es exactamente uno: romper la reversión. Por
     * eso lo que se afirma no es solo que la excepción suba, sino que NO QUEDE
     * EL USUARIO A MEDIAS. Se rompe desde dentro de la transacción con un
     * oyente del modelo, que es el sitio más limpio para meter una avería sin
     * tocar el código de producción.
     */
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    Sanctum::actingAs(User::factory()->create([
        'rol' => 4,
        'area_id' => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => 'gestor',
    ]));

    Domiciliary::created(function () {
        throw new LogicException('averia dentro de la transaccion');
    });

    $subio = false;

    try {
        $this->withoutExceptionHandling()->postJson('/v1/domiciliaries/createDomiciliary', [
            'name' => 'Repartidor Nuevo',
            'email' => 'repartidor.nuevo@prueba.test',
            'password' => 'secreta123',
            'phone' => '3001234567',
        ]);
    } catch (LogicException $e) {
        $subio = true;
    }

    expect($subio)->toBeTrue('La excepcion no subio: se la quedo el catch')
        ->and(DB::table('user')->where('email', 'repartidor.nuevo@prueba.test')->exists())
        ->toBeFalse('Quedo un usuario a medias: la reversion se perdio');
});

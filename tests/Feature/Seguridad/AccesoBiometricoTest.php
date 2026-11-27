<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Volver a entrar con la huella.
 *
 * Lo que de verdad hay que fijar acá no es que funcione —eso se ve— sino que
 * la credencial que queda guardada en el teléfono **no sirva para nada más**.
 *
 * Sanctum guarda las habilidades de cada token pero NO las aplica por su
 * cuenta: `tokenCan()` es una comprobación que hay que escribir. Sin el
 * middleware, ese token entraría en cualquier ruta con `auth:sanctum` y la
 * frase «solo sirve para pedir una sesión» sería mentira. La prueba de abajo
 * es la que impide que alguien lo quite pensando que sobra.
 */
function cuentaVerificada(int $rol = 1): User
{
    Rol::firstOrCreate(['rol_id' => $rol], ['name' => 'rol'.$rol, 'guard_name' => 'web']);

    return User::factory()->create([
        'rol' => $rol,
        'state' => 1,
        'email_verified_at' => now(),
    ]);
}

/** Activa la huella y devuelve el token que el teléfono guardaría. */
function tokenDeHuella(User $u): string
{
    Sanctum::actingAs($u);

    return test()->postJson('/v1/biometrico')->assertOk()->json('token');
}

/* ---------------------------------------------------------------------- */

test('activar devuelve un token y el nombre para saludar', function () {
    $u = cuentaVerificada();
    Sanctum::actingAs($u);

    $r = test()->postJson('/v1/biometrico')->assertOk();

    expect($r->json('token'))->toBeString()->not->toBeEmpty();
    // Lo justo para decir «Entrar como Browin» sin sesión abierta.
    expect($r->json('perfil.nombre'))->toBe($u->name);
    // Ni correo ni teléfono en algo que se lee con la pantalla bloqueada.
    expect($r->json('perfil'))->not->toHaveKey('email');
});

test('con la huella se entra sin escribir la contrasena', function () {
    $u = cuentaVerificada();
    $token = tokenDeHuella($u);

    $r = test()
        ->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/v1/biometrico/entrar')
        ->assertOk();

    expect($r->json('token'))->toBeString();
    expect($r->json('user.user_id'))->toBe($u->user_id);
});

test('ESA credencial no sirve para nada mas', function () {
    $u = cuentaVerificada();
    $token = tokenDeHuella($u);

    /*
     * El corazón de todo esto. Si esta prueba se cae, lo que hay guardado en
     * el teléfono deja de ser «una llave para volver a entrar» y pasa a ser
     * una sesión completa: leer pedidos, ver direcciones, pagar.
     */
    foreach (['/v1/profile', '/v1/users/notifications', '/v1/businesses/index'] as $ruta) {
        test()
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson($ruta)
            ->assertForbidden();
    }
});

test('cerrar sesion NO borra la huella: es justo lo que se pidio', function () {
    $u = cuentaVerificada();
    $token = tokenDeHuella($u);

    Sanctum::actingAs($u);
    test()->postJson('/v1/logout')->assertOk();
    app('auth')->forgetGuards();

    // Y con la huella se vuelve a entrar, que es el objetivo del cambio.
    test()
        ->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/v1/biometrico/entrar')
        ->assertOk();
});

test('olvidar el telefono si la mata', function () {
    $u = cuentaVerificada();
    $token = tokenDeHuella($u);

    Sanctum::actingAs($u);
    test()->deleteJson('/v1/biometrico')->assertOk();
    app('auth')->forgetGuards();

    test()
        ->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/v1/biometrico/entrar')
        ->assertUnauthorized();
});

test('activar otra vez invalida la anterior', function () {
    $u = cuentaVerificada();
    $viejo = tokenDeHuella($u);

    app('auth')->forgetGuards();
    $nuevo = tokenDeHuella($u);
    app('auth')->forgetGuards();

    expect($nuevo)->not->toBe($viejo);

    // El teléfono anterior deja de servir sin que nadie tenga que acordarse.
    test()->withHeader('Authorization', 'Bearer '.$viejo)
        ->postJson('/v1/biometrico/entrar')->assertUnauthorized();
});

test('una cuenta deshabilitada no entra por la huella', function () {
    $u = cuentaVerificada();
    $token = tokenDeHuella($u);

    $u->update(['state' => 0]);
    app('auth')->forgetGuards();

    /*
     * La huella no puede ser una puerta lateral. Bloquear a alguien desde el
     * panel tiene que valer para todas las entradas, o no vale para ninguna.
     */
    test()->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/v1/biometrico/entrar')
        ->assertForbidden()
        ->assertJsonPath('reason', 'account_disabled');
});

test('tampoco sirve en una ruta PUBLICA, que es donde se podia perder', function () {
    /*
     * LA PROPIEDAD QUE CASI SE CEDE.
     *
     * El middleware se movió detrás de `auth:sanctum` para no repetir la
     * búsqueda del token. En las 21 rutas de `/v1` que no piden sesión no hay
     * autenticación que corra, así que ahí no hay token resuelto que leer: sin
     * la búsqueda a mano como respaldo, la credencial dejaría de estar acotada
     * en ellas y la propiedad escrita pasaría de «no sirve para nada más» a
     * «para nada más que requiera sesión».
     *
     * No ganaría privilegio —en una ruta pública tener este token es igual que
     * no tener ninguno— pero una propiedad se cumple o no se cumple, y nadie
     * la había comprobado aquí.
     */
    $u = cuentaVerificada();
    $token = tokenDeHuella($u);

    foreach (['/v1/app/config', '/v1/categories-free?type=1', '/v1/top-businesses-free'] as $ruta) {
        $r = test()
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson($ruta);

        expect($r->status())->toBe(403);
        expect($r->json('reason'))->toBe('biometric_token_scope');
    }

    /*
     * Y sin el token, esas mismas rutas siguen abiertas: el 403 es de la
     * credencial, no de la ruta.
     *
     * `flushHeaders()` ANTES, y costo una vuelta: en Pest `test()` devuelve la
     * MISMA instancia del caso, asi que el `withHeader('Authorization', ...)`
     * del bucle sigue puesto y esta peticion llevaba el token biometrico
     * encima. Daba 403 y parecia que la ruta publica estaba cerrada.
     */
    test()->flushHeaders();
    test()->getJson('/v1/app/config')->assertOk();
});

test('una peticion autenticada busca el token UNA vez, no dos', function () {
    /*
     * EL AHORRO, fijado. Medido antes del cambio en el log de latencia:
     * `personal_access_tokens` salía DOS veces en toda ruta con sesión —la
     * búsqueda de Sanctum y la de este middleware, que corría antes de la
     * autenticación y no tenía usuario que mirar—.
     *
     * Se cuenta la consulta concreta y no el total: el total lo mueve
     * cualquier cosa, y lo que esta prueba defiende es que esa fila no se pida
     * dos veces.
     */
    $u = cuentaVerificada();
    $token = $u->createToken('prueba-de-sesion')->plainTextToken;

    /*
     * SOLO LOS `select`, y la distincion importa: Sanctum tambien ESCRIBE en
     * esa tabla —`last_used_at` en cada peticion— asi que contar cualquier
     * sentencia que la mencione daba 2 y parecia que la busqueda seguia
     * duplicada. Lo que se vigila es que se BUSQUE una vez.
     */
    $veces = 0;
    DB::listen(function ($q) use (&$veces) {
        if (str_contains($q->sql, 'personal_access_tokens')
            && str_starts_with(strtolower(ltrim($q->sql)), 'select')) {
            $veces++;
        }
    });

    test()
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/v1/profile')
        ->assertOk();

    expect($veces)->toBe(1);
});

test('salir cierra solo la sesion desde la que se sale, no el panel ni el otro telefono', function () {
    $u = cuentaVerificada();
    $telefono = $u->createToken('auth_token')->plainTextToken;
    $u->createToken('auth_token'); // el panel de aliados en el computador

    test()->withHeader('Authorization', 'Bearer '.$telefono)
        ->postJson('/v1/logout')->assertOk();

    expect($u->tokens()->count())->toBe(1);
});

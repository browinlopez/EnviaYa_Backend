<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use App\Services\Ajustes;
use Laravel\Sanctum\Sanctum;

/**
 * MODO MANTENIMIENTO DE LA APP
 *
 * Se enciende desde el panel y lo APLICA EL SERVIDOR. Si solo lo mirara la app,
 * una versión vieja —o una que no consulte la bandera— seguiría creando pedidos
 * en mitad de un despliegue, que es exactamente lo que se quiere evitar.
 *
 * Lo que más importa de estas pruebas son las EXCEPCIONES. Un mantenimiento que
 * apaga el panel no se puede apagar; uno que rechaza los avisos de la pasarela
 * pierde la confirmación de pagos que el cliente ya hizo. Las dos cosas son
 * peores que no tener modo mantenimiento.
 */

function encenderMantenimiento(string $mensaje = 'Volvemos en un rato.'): void
{
    Ajustes::guardar([
        'app.mantenimiento'         => true,
        'app.mantenimiento_mensaje' => $mensaje,
    ], null);
}

function comoPersonal(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => Area::NIVEL_GESTOR,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

function comoCliente(): User
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($user);

    return $user;
}

beforeEach(fn () => Ajustes::olvidar());

it('apagado, la app funciona igual', function () {
    comoCliente();

    // Lo que gobierna el middleware es el 503, no el resultado del endpoint: lo
    // que responda por sus propios parámetros es asunto suyo.
    expect($this->getJson('/v1/product/schema?business_id=1')->status())->not->toBe(503);
});

it('encendido, la app recibe 503 con el mensaje escrito', function () {
    encenderMantenimiento('Estamos actualizando. Volvemos a las 3 p. m.');
    comoCliente();

    $this->getJson('/v1/product/schema?business_id=1')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Estamos actualizando. Volvemos a las 3 p. m.')
        ->assertJsonPath('maintenance', true);
});

it('el PANEL sigue vivo: si no, no habría cómo apagarlo', function () {
    encenderMantenimiento();
    comoPersonal();

    /*
     * Es la excepción que más importa. Con el panel también apagado, el
     * mantenimiento quedaría encendido hasta que alguien entrara al servidor por
     * consola — y quien lo encendió desde una pantalla puede no tener acceso.
     */
    $this->getJson('/v1/admin/settings')->assertOk();

    $this->putJson('/v1/admin/settings', ['app' => ['mantenimiento' => false]])
        ->assertOk();

    Ajustes::olvidar();
    expect(Ajustes::valor('app.mantenimiento'))->toBeFalse();
});

it('los avisos de la pasarela de pago pasan igual', function () {
    encenderMantenimiento();

    /*
     * Un cobro en curso se confirma con un webhook. Contestarle 503 significa
     * perder la confirmación de un pago que el cliente YA hizo: la plataforma
     * cobró y el pedido se queda sin pagar.
     *
     * Lo que se comprueba es que NO devuelva 503; el 401/422 que responda por
     * falta de firma es asunto del propio webhook.
     */
    $r = $this->postJson('/v1/webhooks/bold', []);

    expect($r->status())->not->toBe(503);
});

it('preguntar qué pasa nunca se bloquea', function () {
    encenderMantenimiento('En mantenimiento.');

    // Si el endpoint que anuncia el mantenimiento también respondiera 503, la
    // app no tendría forma de saber POR QUÉ falla todo.
    $this->getJson('/v1/app/config')
        ->assertOk()
        ->assertJsonPath('maintenance.active', true)
        ->assertJsonPath('maintenance.message', 'En mantenimiento.');
});

it('iniciar sesión sigue disponible', function () {
    encenderMantenimiento();

    // El personal entra al panel con este endpoint: bloquearlo dejaría fuera a
    // quien tiene que arreglar lo que se está arreglando.
    $r = $this->postJson('/v1/login', ['email' => 'nadie@ejemplo.test', 'password' => 'x']);

    expect($r->status())->not->toBe(503);
});

it('la app pregunta por su plataforma y le contestan de la suya', function () {
    Ajustes::guardar([
        'app.version_minima_android' => '2.4.0',
        'app.version_minima_ios'     => '2.5.1',
    ], null);

    $this->getJson('/v1/app/config?platform=android')
        ->assertOk()
        ->assertJsonPath('version.minimum', '2.4.0');

    $this->getJson('/v1/app/config?platform=ios')
        ->assertOk()
        ->assertJsonPath('version.minimum', '2.5.1');

    // Sin decir la plataforma vienen las dos: el endpoint sirve igual antes de
    // que la app aprenda a mandarla.
    $r = $this->getJson('/v1/app/config')->assertOk();
    expect($r->json('version.minimum'))->toBeNull();
    expect($r->json('version.minimum_android'))->toBe('2.4.0');
});

it('rechaza una versión mínima que no es una versión', function () {
    comoPersonal();

    // "la última" o "v2" no se pueden comparar con nada, y una app que no puede
    // comparar se queda sin saber si tiene que actualizarse.
    $this->putJson('/v1/admin/settings', ['app' => ['version_minima_android' => 'la última']])
        ->assertStatus(422);

    $this->putJson('/v1/admin/settings', ['app' => ['version_minima_android' => '2.4.0']])
        ->assertOk();
});

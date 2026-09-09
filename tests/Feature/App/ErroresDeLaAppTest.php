<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * QUE UN FALLO EN EL TELEFONO DEJE RASTRO.
 *
 * Antes de esto no habia NADA: ni Sentry, ni Crashlytics, ni registro propio.
 * Una excepcion al pintar dejaba la pantalla en blanco y del lado del servidor
 * no se sabia nada; lo unico visible habria sido una desinstalacion.
 *
 * Lo que se fija aca es lo que hace util a un recolector de fallos: que acepte
 * SIEMPRE. Un informe rechazado es un fallo que no se conocera nunca, y el
 * cliente que lo manda ya esta roto — no puede encima gestionar un error.
 */

it('guarda el fallo que manda la app', function () {
    $this->postJson('/v1/app/errores', [
        'mensaje'     => "Cannot read property 'business_id' of undefined",
        'traza'       => 'en <NegocioDetail>',
        'pantalla'    => 'NegocioDetail',
        'plataforma'  => 'ios',
        'app_version' => '1.0.8',
    ])->assertOk();

    $fila = DB::table('client_errors')->first();

    expect($fila->pantalla)->toBe('NegocioDetail')
        ->and($fila->platform)->toBe('ios')
        ->and($fila->app_version)->toBe('1.0.8');
});

it('acepta sin sesion: la app puede reventar antes de entrar', function () {
    /*
     * El caso concreto es resolver la sesion guardada al arrancar. Exigir
     * autenticacion aqui perderia exactamente los fallos del arranque, que son
     * los peores: dejan la app inservible desde el primer segundo.
     */
    $this->postJson('/v1/app/errores', ['mensaje' => 'reventó al arrancar'])
        ->assertOk();

    expect(DB::table('client_errors')->count())->toBe(1);
});

it('si hay sesion, apunta de quien era el telefono', function () {
    \App\Models\Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $ana = User::factory()->create(['rol' => 1]);

    Sanctum::actingAs($ana);

    $this->postJson('/v1/app/errores', ['mensaje' => 'boom'])->assertOk();

    expect((int) DB::table('client_errors')->value('user_id'))->toBe((int) $ana->user_id);
});

it('un informe mal formado NO devuelve error', function () {
    /*
     * Esto es lo importante de todo el archivo. Quien llama es el manejador de
     * errores de una app que ya se rompio: si aca se responde 422, la app tiene
     * que gestionar un error DENTRO del manejador del error anterior. Se
     * responde que si, se registra el problema, y se sigue.
     */
    $this->postJson('/v1/app/errores', [])->assertOk();
    $this->postJson('/v1/app/errores', ['mensaje' => null])->assertOk();
});

it('una traza enorme se recorta en vez de reventar la columna', function () {
    // En React Native la pila trae referencias al paquete entero: cientos de
    // kilobytes por informe.
    $this->postJson('/v1/app/errores', [
        'mensaje' => 'boom',
        'traza'   => str_repeat('x', 50000),
    ])->assertOk();

    expect(mb_strlen((string) DB::table('client_errors')->value('traza')))
        ->toBeLessThanOrEqual(4000);
});

it('un mensaje mas largo que la columna tampoco la revienta', function () {
    $this->postJson('/v1/app/errores', [
        'mensaje' => str_repeat('y', 400),
    ])->assertOk();

    expect(DB::table('client_errors')->count())->toBe(1);
});

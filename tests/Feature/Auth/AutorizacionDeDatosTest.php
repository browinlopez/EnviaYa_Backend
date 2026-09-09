<?php

use App\Models\Rol;
use App\Models\User;

/**
 * SIN AUTORIZACION NO HAY CUENTA.
 *
 * La Ley 1581 pide autorizacion PREVIA, EXPRESA E INFORMADA para tratar datos
 * personales, y pide poder DEMOSTRARLA. El registro de la app no pedia nada:
 * recogia nombre, telefono, correo, direccion y ubicacion, y lo ataba al
 * historial de compras, sin una casilla ni un enlace a la politica.
 *
 * Lo dificil de defender era el contraste dentro del propio proyecto: el
 * formulario de la WEB si la exigia —no guarda nada sin el consentimiento
 * marcado, y anota la hora— mientras el registro de la aplicacion, que recoge
 * mucho mas, no preguntaba.
 */

beforeEach(function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
});

/** Lo minimo que pide el registro, sin el consentimiento. */
function datosDeRegistro(array $extra = []): array
{
    return array_merge([
        'name'     => 'Ana Perez',
        'email'    => 'ana' . uniqid() . '@example.com',
        'password' => 'ClaveDePrueba1',
        'phone'    => '3001234567',
    ], $extra);
}

it('sin el consentimiento no se crea la cuenta', function () {
    $datos = datosDeRegistro();

    $this->postJson('/v1/register', $datos)
        ->assertStatus(422)
        ->assertJsonValidationErrors('consentimiento');

    expect(User::where('email', $datos['email'])->exists())->toBeFalse();
});

it('un consentimiento en false se rechaza igual que si faltara', function () {
    /*
     * `accepted` y no `boolean`: decir «no» explicitamente tiene que valer lo
     * mismo que no decir nada. Con `boolean` un `false` habria pasado la
     * validacion y la cuenta se habria creado sin autorizacion.
     */
    $datos = datosDeRegistro(['consentimiento' => false]);

    $this->postJson('/v1/register', $datos)
        ->assertStatus(422)
        ->assertJsonValidationErrors('consentimiento');
});

it('con el consentimiento queda la constancia: cuando, que version y desde donde', function () {
    $datos = datosDeRegistro([
        'consentimiento'   => true,
        'politica_version' => '2026-09',
    ]);

    $this->postJson('/v1/register', $datos)->assertSuccessful();

    $usuario = User::where('email', $datos['email'])->first();

    expect($usuario)->not->toBeNull()
        ->and($usuario->policy_accepted_at)->not->toBeNull()
        ->and($usuario->policy_version)->toBe('2026-09')
        ->and($usuario->policy_ip)->not->toBeNull();
});

it('la hora la pone el SERVIDOR, no el telefono', function () {
    /*
     * Una fecha que manda el cliente no demuestra nada, porque el cliente la
     * elige. Si se aceptara la del telefono, bastaria con cambiar la hora del
     * aparato para fabricar una autorizacion con la fecha que convenga.
     */
    $datos = datosDeRegistro([
        'consentimiento'   => true,
        'politica_version' => '2026-09',
        // Un intento de imponer una fecha desde fuera.
        'policy_accepted_at' => '2001-01-01 00:00:00',
    ]);

    $this->postJson('/v1/register', $datos)->assertSuccessful();

    $usuario = User::where('email', $datos['email'])->first();

    expect($usuario->policy_accepted_at->year)->toBe(now()->year);
});

it('el mensaje explica que falta, en español', function () {
    // Lo lee una persona en su telefono: «The consentimiento must be accepted»
    // no le dice nada.
    $this->postJson('/v1/register', datosDeRegistro(['consentimiento' => false]))
        ->assertStatus(422)
        ->assertJsonPath('errors.consentimiento.0', 'Falta la autorizacion de tratamiento de datos.');
});

it('a una compilacion sin la casilla se le dice que actualice, no que le falta marcarla', function () {
    /*
     * La version publicada en las tiendas es anterior a la casilla: no manda
     * el campo y no hay forma de que lo mande. Tampoco se puede arreglar por
     * aire, porque `expo-updates` entro despues de esa compilacion.
     *
     * Decirle «falta la autorizacion» le describe una casilla que su pantalla
     * no tiene: lee que hizo algo mal y no hay nada que pueda hacer. Se
     * distingue por la AUSENCIA del campo, que es lo unico que separa una
     * compilacion vieja de una nueva.
     */
    $datos = datosDeRegistro();

    $respuesta = $this->postJson('/v1/register', $datos)->assertStatus(422);

    expect($respuesta->json('errors.consentimiento.0'))->toContain('Actualizala desde la tienda');

    // Y lo que no cambia: sigue sin crearse la cuenta.
    expect(User::where('email', $datos['email'])->exists())->toBeFalse();
});

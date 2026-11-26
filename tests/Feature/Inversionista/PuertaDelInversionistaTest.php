<?php

/**
 * LA PUERTA DEL INVERSIONISTA.
 *
 * El rol 7 entra a `/inversionista/*` y a nada más del panel de aliados; los
 * demás roles no entran al suyo. Es la cuarta puerta del proyecto y se prueba
 * igual que las otras: por rol, en las dos direcciones, y con la cuenta
 * deshabilitada.
 */

use App\Models\Rol;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function invCuenta(int $rol, bool $habilitada = true): User
{
    $nombres = [1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario', 4 => 'admin', 5 => 'dueno_conjunto', 6 => 'celador', 7 => 'inversionista'];
    Rol::firstOrCreate(['rol_id' => $rol], ['name' => $nombres[$rol], 'guard_name' => 'web']);

    return User::factory()->create(['rol' => $rol, 'state' => $habilitada ? 1 : 0]);
}

test('la migracion deja el rol 7 creado', function () {
    expect(Rol::find(7)?->name)->toBe('inversionista');
});

test('el inversionista entra a su ambito con la forma de los otros', function () {
    Sanctum::actingAs(invCuenta(7));

    $this->getJson('/v1/inversionista/me')
        ->assertOk()
        ->assertExactJson([
            'area' => ['name' => "VeciPa'Ya"],
            'role' => 'inversionista',
            'permissions' => ['tablero' => ['view' => true, 'manage' => false]],
            'stats' => [],
        ]);

    $this->getJson('/v1/inversionista/tablero')->assertOk()
        ->assertJsonStructure(['periodo', 'filtros', 'cifras', 'por_tipo', 'meses', 'por_ciudad', 'opciones']);
});

test('sin sesion no hay tablero', function () {
    $this->getJson('/v1/inversionista/tablero')->assertUnauthorized();
});

test('ningun otro rol entra al tablero', function (int $rol) {
    Sanctum::actingAs(invCuenta($rol));

    $this->getJson('/v1/inversionista/me')->assertForbidden();
    $this->getJson('/v1/inversionista/tablero')->assertForbidden();
})->with([
    'comprador' => 1,
    'tendero' => 2,
    'domiciliario' => 3,
    'personal interno' => 4,
    'dueno de conjunto' => 5,
    'celador' => 6,
]);

test('el inversionista no entra al panel del negocio ni al del conjunto', function () {
    Sanctum::actingAs(invCuenta(7));

    $this->getJson('/v1/negocio/me')->assertForbidden();
    $this->getJson('/v1/negocio/pedidos')->assertForbidden();
    $this->getJson('/v1/conjunto/me')->assertForbidden();
    $this->getJson('/v1/conjunto/residentes')->assertForbidden();
});

test('una cuenta de inversionista deshabilitada no entra', function () {
    Sanctum::actingAs(invCuenta(7, habilitada: false));

    $this->getJson('/v1/inversionista/tablero')
        ->assertForbidden()
        ->assertJsonPath('reason', 'account_disabled');
});

test('los filtros mal escritos se rechazan con 422', function () {
    Sanctum::actingAs(invCuenta(7));

    $this->getJson('/v1/inversionista/tablero?desde=ayer-no&municipality_id=abc')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['desde', 'municipality_id']);
});

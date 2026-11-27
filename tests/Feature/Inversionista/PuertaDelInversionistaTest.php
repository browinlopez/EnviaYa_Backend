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

/** Todo lo que cuelga de `/inversionista`, sin `me`. */
const INV_RUTAS = ['opciones', 'resumen', 'negocios', 'expansion', 'usuarios', 'operacion', 'informe'];

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
            'permissions' => array_fill_keys(
                ['resumen', 'negocios', 'expansion', 'usuarios', 'operacion', 'informes'],
                ['view' => true, 'manage' => false],
            ),
            'stats' => [],
        ]);

    foreach (['resumen', 'negocios', 'expansion', 'usuarios', 'operacion'] as $modulo) {
        $this->getJson('/v1/inversionista/'.$modulo)->assertOk()->assertJsonStructure(['periodo', 'filtros']);
    }
    $this->getJson('/v1/inversionista/opciones')->assertOk()->assertJsonStructure(['ciudades', 'tipos']);
});

test('sin sesion no hay ningun modulo', function () {
    foreach (['me', ...INV_RUTAS] as $ruta) {
        $this->getJson('/v1/inversionista/'.$ruta)->assertUnauthorized();
    }
});

test('el tablero de antes ya no existe: se partio en modulos', function () {
    Sanctum::actingAs(invCuenta(7));

    $this->getJson('/v1/inversionista/tablero')->assertNotFound();
});

test('ningun otro rol entra a ningun modulo', function (int $rol) {
    Sanctum::actingAs(invCuenta($rol));

    foreach (['me', ...INV_RUTAS] as $ruta) {
        $this->getJson('/v1/inversionista/'.$ruta)->assertForbidden();
    }
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

    foreach (INV_RUTAS as $ruta) {
        $this->getJson('/v1/inversionista/'.$ruta)
            ->assertForbidden()
            ->assertJsonPath('reason', 'account_disabled');
    }
});

test('los filtros mal escritos se rechazan con 422', function () {
    Sanctum::actingAs(invCuenta(7));

    foreach (['resumen', 'negocios', 'expansion', 'usuarios', 'operacion', 'informe'] as $modulo) {
        $this->getJson('/v1/inversionista/'.$modulo.'?desde=ayer-no&municipality_id=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['desde', 'municipality_id']);
    }
});

test('una ventana de mas de 366 dias se rechaza en vez de recortarse callada', function () {
    Sanctum::actingAs(invCuenta(7));

    $this->getJson('/v1/inversionista/resumen?desde=2025-01-01&hasta=2026-11-20')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['hasta']);

    // Un año justo, sí (y al revés se endereza, como en los reportes).
    $this->getJson('/v1/inversionista/resumen?desde=2026-11-20&hasta=2025-11-20')->assertOk()
        ->assertJsonPath('periodo.dias', 366);
    $this->getJson('/v1/inversionista/informe?formato=docx')->assertStatus(422)->assertJsonValidationErrors(['formato']);
});

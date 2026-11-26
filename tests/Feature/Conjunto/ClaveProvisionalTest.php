<?php

/**
 * LA CLAVE QUE ENTREGA EL ADMINISTRADOR ES PROVISIONAL.
 *
 * El celador entra la primera vez con la clave que le escribió el
 * administrador. Hasta que ponga la suya, el panel del conjunto entero le
 * responde 403 `must_change_password`; el perfil, el cambio de clave y cerrar
 * sesión siguen abiertos, que son justo lo que necesita para salir de ahí.
 */

use App\Models\Conjunto\ComplexStaff;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function celadorRecienCreado(): array
{
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    test()->postJson('/v1/conjunto/celadores', [
        'name' => 'Pedro Vigilante',
        'email' => 'pedro@ejemplo.test',
        'password' => 'ClaveDePrueba1*',
    ])->assertCreated();

    return [...$c, 'celador' => User::where('email', 'pedro@ejemplo.test')->first()];
}

test('el celador recien creado sale con la clave provisional en el login', function () {
    celadorRecienCreado();

    $r = $this->postJson('/v1/login', [
        'email' => 'pedro@ejemplo.test',
        'password' => 'ClaveDePrueba1*',
    ])->assertOk();

    expect($r->json('user.must_change_password'))->toBeTrue();
});

test('con la clave provisional la porteria responde 403 con el motivo', function () {
    $c = celadorRecienCreado();
    Sanctum::actingAs($c['celador']);

    foreach (['/v1/conjunto/porteria/entradas', '/v1/conjunto/porteria/adentro', '/v1/conjunto/resumen'] as $ruta) {
        $this->getJson($ruta)->assertForbidden()->assertJsonPath('reason', 'must_change_password');
    }

    $this->postJson('/v1/conjunto/porteria/visitantes', ['name' => 'Marta', 'kind' => 'visita'])
        ->assertForbidden()->assertJsonPath('reason', 'must_change_password');

    // Lo que necesita para salir de ahí sigue abierto.
    $this->getJson('/v1/profile')->assertOk()->assertJsonPath('must_change_password', true);
    $this->getJson('/v1/conjunto/me')->assertOk();
});

test('cambiar la clave apaga la marca y abre la porteria', function () {
    $c = celadorRecienCreado();
    Sanctum::actingAs($c['celador']);

    $this->putJson('/v1/users/password', [
        'current_password' => 'ClaveDePrueba1*',
        'password' => 'MiClavePropia9#',
        'password_confirmation' => 'MiClavePropia9#',
    ])->assertOk();

    expect($c['celador']->fresh()->must_change_password)->toBeFalse();

    Sanctum::actingAs($c['celador']->fresh());
    $this->getJson('/v1/conjunto/porteria/entradas')->assertOk();
});

test('restablecer la clave de un celador la vuelve provisional y cierra sus sesiones', function () {
    $c = celadorRecienCreado();
    $celador = $c['celador'];
    $celador->forceFill(['must_change_password' => false])->save();
    $celador->createToken('auth_token');

    $ficha = ComplexStaff::where('user_id', $celador->user_id)->first();

    Sanctum::actingAs($c['user']);
    $this->putJson("/v1/conjunto/celadores/{$ficha->id}", ['password' => 'OtraClave123*'])->assertOk();

    $celador->refresh();
    expect($celador->must_change_password)->toBeTrue()
        ->and(Hash::check('OtraClave123*', $celador->password))->toBeTrue()
        ->and($celador->tokens()->count())->toBe(0);
});

test('la marca no bloquea a un administrador de conjunto', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    $c['user']->forceFill(['must_change_password' => true])->save();
    Sanctum::actingAs($c['user']->fresh());

    $this->getJson('/v1/conjunto/celadores')->assertOk();
});

test('la marca no se puede poner desde el cuerpo del alta', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    // Aunque viniera en falso, el alta la enciende: la clave la escribió otro.
    $this->postJson('/v1/conjunto/celadores', [
        'name' => 'Ana', 'email' => 'ana@ejemplo.test',
        'password' => 'ClaveDePrueba1*', 'must_change_password' => false,
    ])->assertCreated();

    expect(User::where('email', 'ana@ejemplo.test')->first()->must_change_password)->toBeTrue();
});

test('el listado de celadores dice quien sigue con la clave provisional', function () {
    celadorRecienCreado();

    $fila = collect($this->getJson('/v1/conjunto/celadores')->assertOk()->json('data'))
        ->firstWhere('email', 'pedro@ejemplo.test');

    expect($fila['must_change_password'])->toBeTrue();
});

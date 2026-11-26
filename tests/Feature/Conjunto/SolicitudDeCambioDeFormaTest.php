<?php

use App\Models\Conjunto\ComplexStaff;
use App\Models\Operacion\Pqrs;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * PEDIR OTRAS TORRES O APARTAMENTOS: radica un PQRS de petición, deja la
 * solicitud «en revisión» y NO cambia el conjunto.
 */
test('la solicitud radica un pqrs de peticion y no toca el conjunto', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    $this->getJson('/v1/conjunto/perfil/cambio-de-forma')->assertOk()->assertJsonPath('data', null);

    $r = $this->postJson('/v1/conjunto/perfil/cambio-de-forma', [
        'towers_count' => 5,
        'apartments_per_tower' => 24,
        'note' => 'Se entregó la torre 5 en octubre.',
    ])->assertCreated();

    expect($r->json('data.estado'))->toBe('en_revision')
        ->and($r->json('data.towers_count'))->toBe(5)
        ->and($r->json('data.towers_before'))->toBe(4)
        ->and($r->json('data.code'))->not->toBeNull();

    $pqrs = Pqrs::where('code', $r->json('data.code'))->first();
    expect($pqrs->type)->toBe('peticion')
        ->and((int) $pqrs->user_id)->toBe($c['user']->user_id)
        ->and($pqrs->description)->toContain('Hoy: 4 torres × 20 apartamentos por torre (80 unidades).')
        ->and($pqrs->description)->toContain('Pide: 5 torres × 24 apartamentos por torre (120 unidades).')
        ->and($pqrs->description)->toContain('Se entregó la torre 5 en octubre.');

    // El conjunto sigue como estaba: lo cambia el equipo, no el administrador.
    $conjunto = DB::table('residential_complexes')->where('complex_id', $c['complexId'])->first();
    expect((int) $conjunto->towers_count)->toBe(4)
        ->and((int) $conjunto->apartments_per_tower)->toBe(20);

    $this->getJson('/v1/conjunto/perfil/cambio-de-forma')->assertOk()->assertJsonPath('data.estado', 'en_revision');
});

test('con una en revision no se manda otra; resuelta, si', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    $cuerpo = ['towers_count' => 6, 'apartments_per_tower' => 20];
    $code = $this->postJson('/v1/conjunto/perfil/cambio-de-forma', $cuerpo)->assertCreated()->json('data.code');
    $this->postJson('/v1/conjunto/perfil/cambio-de-forma', $cuerpo)->assertStatus(409);

    Pqrs::where('code', $code)->update(['state' => Pqrs::RESUELTO, 'resolution' => 'Actualizado a 6 torres.']);

    $this->getJson('/v1/conjunto/perfil/cambio-de-forma')
        ->assertJsonPath('data.estado', 'resuelta')
        ->assertJsonPath('data.resolution', 'Actualizado a 6 torres.');

    $this->postJson('/v1/conjunto/perfil/cambio-de-forma', ['towers_count' => 7, 'apartments_per_tower' => 20])
        ->assertCreated();
});

test('pedir lo mismo que ya tiene es un 422', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/perfil/cambio-de-forma', ['towers_count' => 4, 'apartments_per_tower' => 20])
        ->assertStatus(422)->assertJsonValidationErrors('towers_count');
});

test('el celador no pide cambios del conjunto', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);
    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/perfil/cambio-de-forma', ['towers_count' => 9, 'apartments_per_tower' => 9])
        ->assertForbidden();
    $this->getJson('/v1/conjunto/perfil/cambio-de-forma')->assertForbidden();
});

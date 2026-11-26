<?php

use App\Models\Conjunto\ComplexShift;
use App\Models\Conjunto\ComplexStaff;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Sin turno iniciado, el celador no deja pasar a nadie: ni verifica, ni anota
 * visitas, ni marca salidas. Mirar el libro sí puede.
 */
function visitaAdentro(int $complexId): int
{
    return DB::table('complex_entries')->insertGetId([
        'complex_id' => $complexId, 'kind' => 'visitante', 'method' => 'manual',
        'visitor_name' => 'Marta Robles', 'orders_count' => 0,
        'created_at' => now()->subMinutes(20), 'updated_at' => now(),
    ]);
}

test('sin turno el celador no verifica, no anota visitas ni marca salidas', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR, enTurno: false);
    $id = visitaAdentro($c['complexId']);
    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/porteria/verificar', ['document' => '1090123456'])
        ->assertStatus(409)->assertJsonPath('reason', 'sin_turno');
    $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind' => 'visitante', 'visitor_name' => 'Pedro', 'tower' => '1', 'apartment' => '101',
    ])->assertStatus(409)->assertJsonPath('reason', 'sin_turno');
    $this->putJson("/v1/conjunto/porteria/entradas/{$id}/salida")
        ->assertStatus(409)->assertJsonPath('reason', 'sin_turno');

    // Lo que es solo mirar, sí.
    $this->getJson('/v1/conjunto/porteria/entradas')->assertOk();
    $this->getJson('/v1/conjunto/porteria/adentro')->assertOk();

    expect(DB::table('complex_entries')->where('complex_id', $c['complexId'])->count())->toBe(1);
});

test('al iniciar el turno ya puede dejar pasar', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR, enTurno: false);
    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/turno/iniciar')->assertSuccessful();

    $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind' => 'visitante', 'visitor_name' => 'Pedro', 'tower' => '1', 'apartment' => '101',
    ])->assertCreated();
});

test('al terminar el turno se le vuelve a cerrar la puerta', function () {
    $c = conjuntoConPersonal();
    $id = visitaAdentro($c['complexId']);
    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/turno/terminar')->assertSuccessful();

    $this->putJson("/v1/conjunto/porteria/entradas/{$id}/salida")
        ->assertStatus(409)->assertJsonPath('reason', 'sin_turno');
});

test('el administrador del conjunto no necesita turno', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    expect(ComplexShift::count())->toBe(0);

    $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind' => 'visitante', 'visitor_name' => 'Pedro', 'tower' => '1', 'apartment' => '101',
    ])->assertCreated();
});

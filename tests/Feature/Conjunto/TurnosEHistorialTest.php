<?php

use App\Models\Conjunto\ComplexShift;
use App\Models\Conjunto\ComplexStaff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * TURNOS, HISTORIAL DEL CELADOR Y AVISO DE ESTADÍAS LARGAS.
 */
function celadorDelDueno(array $c, string $nombre = 'Ramiro'): array
{
    $u = User::factory()->create(['rol' => 6, 'name' => $nombre]);
    $ficha = ComplexStaff::create([
        'user_id' => $u->user_id, 'complex_id' => $c['complexId'],
        'role' => ComplexStaff::CELADOR, 'state' => true,
    ]);

    return [$u, $ficha];
}

/* ------------------------------ TURNOS ------------------------------ */

test('el celador abre su turno y no puede abrir otro encima', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR, enTurno: false);
    Sanctum::actingAs($c['user']);

    $this->getJson('/v1/conjunto/turno')->assertOk()->assertJsonPath('data', null);

    $this->postJson('/v1/conjunto/turno/iniciar')->assertCreated()->assertJsonPath('data.abierto', true);
    $this->postJson('/v1/conjunto/turno/iniciar')->assertStatus(409);

    expect(ComplexShift::where('user_id', $c['user']->user_id)->count())->toBe(1);

    $this->getJson('/v1/conjunto/turno')->assertOk()->assertJsonPath('data.abierto', true);
});

test('terminar cierra el turno y sin turno abierto responde 422', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR, enTurno: false);
    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/turno/terminar')->assertStatus(422);

    $this->travelTo(now()->subHours(3));
    $this->postJson('/v1/conjunto/turno/iniciar')->assertCreated();
    $this->travelBack();

    $r = $this->postJson('/v1/conjunto/turno/terminar')->assertOk();

    expect($r->json('data.abierto'))->toBeFalse()
        ->and($r->json('data.minutos'))->toBeGreaterThanOrEqual(179);
});

test('el administrador no lleva turno pero ve quien esta en la puerta', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    [$ramiro] = celadorDelDueno($c);
    ComplexShift::create(['complex_id' => $c['complexId'], 'user_id' => $ramiro->user_id, 'started_at' => now()->subHours(2)]);

    Sanctum::actingAs($c['user']);
    $this->postJson('/v1/conjunto/turno/iniciar')->assertForbidden();

    $r = $this->getJson('/v1/conjunto/turnos?abiertos=1')->assertOk();
    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.name'))->toBe('Ramiro');

    $fila = collect($this->getJson('/v1/conjunto/celadores')->json('data'))->firstWhere('name', 'Ramiro');
    expect($fila['en_turno_desde'])->not->toBeNull();
});

test('el celador no ve la lista de turnos del conjunto', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);
    Sanctum::actingAs($c['user']);

    $this->getJson('/v1/conjunto/turnos')->assertForbidden();
});

test('desactivar a un celador le cierra el turno', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    [$ramiro, $ficha] = celadorDelDueno($c);
    ComplexShift::create(['complex_id' => $c['complexId'], 'user_id' => $ramiro->user_id, 'started_at' => now()->subHour()]);

    Sanctum::actingAs($c['user']);
    $this->putJson("/v1/conjunto/celadores/{$ficha->id}", ['state' => false])->assertOk();

    expect(ComplexShift::abiertoDe($ramiro->user_id))->toBeNull();
});

/* ----------------------------- HISTORIAL ----------------------------- */

test('el historial junta entradas, salidas, turnos y bajas, del mas reciente al mas viejo', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    [$ramiro, $ficha] = celadorDelDueno($c);
    DB::table('complex_staff')->where('id', $ficha->id)->update(['created_at' => now()->subDays(3)]);

    DB::table('complex_entries')->insert([
        'complex_id' => $c['complexId'], 'kind' => 'visitante', 'method' => 'manual',
        'visitor_name' => 'Marta', 'orders_count' => 0, 'registered_by' => $ramiro->user_id,
        'exited_at' => now()->subMinutes(30), 'exit_registered_by' => $ramiro->user_id,
        'created_at' => now()->subHour(), 'updated_at' => now(),
    ]);
    ComplexShift::create([
        'complex_id' => $c['complexId'], 'user_id' => $ramiro->user_id,
        'started_at' => now()->subHours(2), 'ended_at' => now()->subMinutes(10),
    ]);

    // El paquete no audita en consola, y las pruebas corren en consola.
    config(['audit.console' => true]);
    Sanctum::actingAs($c['user']);
    $this->travel(1)->minutes();
    $this->putJson("/v1/conjunto/celadores/{$ficha->id}", ['state' => false])->assertOk();

    $r = $this->getJson("/v1/conjunto/celadores/{$ficha->id}/historial")->assertOk();
    $tipos = collect($r->json('data'))->pluck('tipo')->all();

    expect($tipos)->toContain('entrada', 'salida', 'turno_inicio', 'turno_fin', 'desactivado', 'alta')
        ->and($r->json('meta.total'))->toBe(count($tipos))
        ->and($tipos[0])->toBe('desactivado')
        ->and(end($tipos))->toBe('alta');

    $fechas = collect($r->json('data'))->pluck('fecha')->all();
    $ordenadas = $fechas;
    rsort($ordenadas);
    expect($fechas)->toBe($ordenadas);

    expect(collect($r->json('data'))->firstWhere('tipo', 'entrada')['texto'])->toContain('Marta');
});

test('el historial se pagina sin repetir eventos', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    [$ramiro, $ficha] = celadorDelDueno($c);
    DB::table('complex_staff')->where('id', $ficha->id)->update(['created_at' => now()->subDays(3)]);

    foreach (range(1, 12) as $i) {
        DB::table('complex_entries')->insert([
            'complex_id' => $c['complexId'], 'kind' => 'visitante', 'method' => 'manual',
            'visitor_name' => "Visita {$i}", 'orders_count' => 0, 'registered_by' => $ramiro->user_id,
            'created_at' => now()->subMinutes($i), 'updated_at' => now(),
        ]);
    }

    Sanctum::actingAs($c['user']);
    $uno = $this->getJson("/v1/conjunto/celadores/{$ficha->id}/historial?per_page=5&page=1")->json();
    $dos = $this->getJson("/v1/conjunto/celadores/{$ficha->id}/historial?per_page=5&page=2")->json();

    expect($uno['meta']['total'])->toBe(13) // 12 entradas y el alta
        ->and($uno['data'])->toHaveCount(5)
        ->and(array_intersect(array_column($uno['data'], 'texto'), array_column($dos['data'], 'texto')))->toBe([]);
});

test('el historial de un celador ajeno es 404 y el celador no lo ve', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    $otro = conjuntoConPersonal(ComplexStaff::DUENO);
    [, $fichaAjena] = celadorDelDueno($otro);

    Sanctum::actingAs($c['user']);
    $this->getJson("/v1/conjunto/celadores/{$fichaAjena->id}/historial")->assertNotFound();

    $cel = conjuntoConPersonal(ComplexStaff::CELADOR);
    Sanctum::actingAs($cel['user']);
    $this->getJson("/v1/conjunto/celadores/{$fichaAjena->id}/historial")->assertForbidden();
});

/* --------------------------- ESTADÍAS LARGAS --------------------------- */

test('siguen adentro marca a quien pasa de las horas de alerta', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);
    Sanctum::actingAs($c['user']);
    $this->travelTo(now()->setTime(18, 0));

    $entrar = fn (string $nombre, int $horas) => DB::table('complex_entries')->insert([
        'complex_id' => $c['complexId'], 'kind' => 'visitante', 'method' => 'manual',
        'visitor_name' => $nombre, 'orders_count' => 0,
        'created_at' => now()->subHours($horas), 'updated_at' => now(),
    ]);
    $entrar('Lleva cinco', 5);
    $entrar('Lleva una', 1);

    $r = $this->getJson('/v1/conjunto/porteria/adentro')->assertOk();
    $filas = collect($r->json('data'))->keyBy('nombre');

    expect($r->json('horas_alerta'))->toBe(4)
        ->and($r->json('demorados'))->toBe(1)
        ->and($filas['Lleva cinco']['demorado'])->toBeTrue()
        ->and($filas['Lleva cinco']['minutos_adentro'])->toBe(300)
        ->and($filas['Lleva una']['demorado'])->toBeFalse();

    expect($this->getJson('/v1/conjunto/resumen')->json('hoy.demorados'))->toBe(1);

    // Con 6 horas de alerta en el conjunto, nadie está demorado.
    DB::table('residential_complexes')->where('complex_id', $c['complexId'])->update(['horas_alerta_adentro' => 6]);
    expect($this->getJson('/v1/conjunto/porteria/adentro')->json('demorados'))->toBe(0);
});

test('el administrador fija las horas de alerta y la ficha las devuelve', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    expect($this->getJson('/v1/conjunto/me')->json('complex.horas_alerta_adentro'))->toBe(4);

    $this->putJson('/v1/conjunto/perfil', ['name' => 'Los Almendros', 'horas_alerta_adentro' => 2])
        ->assertOk()->assertJsonPath('complex.horas_alerta_adentro', 2);

    $this->putJson('/v1/conjunto/perfil', ['name' => 'Los Almendros', 'horas_alerta_adentro' => 48])
        ->assertStatus(422);
});

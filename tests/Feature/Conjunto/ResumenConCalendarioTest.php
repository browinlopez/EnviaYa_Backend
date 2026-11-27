<?php

use App\Models\Conjunto\ComplexStaff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL RESUMEN DEL CONJUNTO CON EL CALENDARIO DEL PANEL: entre dos fechas,
 * además de los últimos `dias` (lo que pide la portería). Todo el tablero
 * mira el mismo periodo, y un periodo que ya terminó no suma lo de hoy.
 */
beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-20 15:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function entradaALaTorre(int $complexId, string $torre, string $cuando, string $metodo = 'codigo'): void
{
    DB::table('complex_entries')->insert([
        'complex_id' => $complexId, 'kind' => 'domiciliario', 'domiciliary_id' => null,
        'method' => $metodo, 'orders_count' => 0, 'tower' => $torre,
        'created_at' => $cuando, 'updated_at' => $cuando,
    ]);
}

test('entre dos fechas: la serie, las horas, las torres y la identificación son de ese rango', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    entradaALaTorre($c['complexId'], '1', '2026-11-09 23:00:00');           // antes
    entradaALaTorre($c['complexId'], '2', '2026-11-10 08:00:00');           // dentro
    entradaALaTorre($c['complexId'], '2', '2026-11-12 20:00:00', 'cedula'); // dentro, el último día
    entradaALaTorre($c['complexId'], '3', '2026-11-13 09:00:00');           // después
    entradaALaTorre($c['complexId'], '3', '2026-11-20 10:00:00');           // hoy

    $r = $this->getJson('/v1/conjunto/resumen?desde=2026-11-10&hasta=2026-11-12')->assertOk();

    expect($r->json('dias'))->toBe(3)
        ->and($r->json('desde'))->toBe('2026-11-10')
        ->and($r->json('hasta'))->toBe('2026-11-12')
        ->and(collect($r->json('series'))->pluck('dia')->all())->toBe(['2026-11-10', '2026-11-11', '2026-11-12'])
        ->and(collect($r->json('series'))->sum('entradas'))->toBe(2)
        ->and(collect($r->json('por_hora'))->sum('total'))->toBe(2)
        ->and($r->json('torres'))->toBe([['torre' => '2', 'entradas' => 2]])
        ->and($r->json('identificacion.codigo'))->toBe(1)
        ->and($r->json('identificacion.cedula'))->toBe(1)
        // Lo de hoy sigue siendo de hoy, elija quien elija el periodo.
        ->and($r->json('hoy.entradas'))->toBe(1);
});

test('dias sigue funcionando como siempre (la portería pide dias=1)', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);
    entradaALaTorre($c['complexId'], '3', '2026-11-20 10:00:00');
    entradaALaTorre($c['complexId'], '3', '2026-11-19 10:00:00');

    $hoy = $this->getJson('/v1/conjunto/resumen?dias=1')->assertOk();
    expect($hoy->json('dias'))->toBe(1)
        ->and($hoy->json('desde'))->toBe('2026-11-20')
        ->and($hoy->json('torres.0.entradas'))->toBe(1);

    // Sin nada, catorce días; y se acota a 90 como antes.
    expect($this->getJson('/v1/conjunto/resumen')->json('dias'))->toBe(14)
        ->and($this->getJson('/v1/conjunto/resumen?dias=365')->json('dias'))->toBe(90);
});

test('más de 92 días o un rango al revés es un 422', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    $this->getJson('/v1/conjunto/resumen?desde=2026-01-01&hasta=2026-11-20')
        ->assertStatus(422)->assertJsonValidationErrors('hasta');
    $this->getJson('/v1/conjunto/resumen?desde=2026-11-20&hasta=2026-11-01')
        ->assertStatus(422)->assertJsonValidationErrors('hasta');
    // Un trimestre del calendario sí cabe.
    $this->getJson('/v1/conjunto/resumen?desde=2026-08-01&hasta=2026-10-31')->assertOk();
});

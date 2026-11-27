<?php

use App\Models\Conjunto\ComplexStaff;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL LIBRO DE LA PORTERÍA, PAGINADO EN EL SERVIDOR.
 *
 * Lo que importa no es que «haya páginas» sino que las cifras de la cabecera
 * salgan del DÍA entero y no de la página servida, y que el tope por página
 * no se pueda saltar desde la URL.
 */
function entradaDelLibro(int $complexId, array $extra = []): int
{
    return DB::table('complex_entries')->insertGetId([
        'complex_id' => $complexId, 'kind' => 'visitante', 'method' => 'manual',
        'visitor_name' => 'Visita', 'orders_count' => 0,
        'created_at' => now(), 'updated_at' => now(),
        ...$extra,
    ]);
}

test('sin pedir pagina sirve 50 y dice cuantas hay', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    foreach (range(1, 60) as $i) {
        entradaDelLibro($c['complexId'], ['visitor_name' => "Visita {$i}"]);
    }

    $r = $this->getJson('/v1/conjunto/porteria/entradas')->assertOk();

    expect($r->json('data'))->toHaveCount(50)
        ->and($r->json('meta.total'))->toBe(60)
        ->and($r->json('meta.last_page'))->toBe(2)
        ->and($r->json('meta.per_page'))->toBe(50);

    $r2 = $this->getJson('/v1/conjunto/porteria/entradas?page=2')->assertOk();
    expect($r2->json('data'))->toHaveCount(10);
});

test('per_page no pasa de 100', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    foreach (range(1, 120) as $i) {
        entradaDelLibro($c['complexId']);
    }

    $r = $this->getJson('/v1/conjunto/porteria/entradas?per_page=5000')->assertOk();

    expect($r->json('data'))->toHaveCount(100)
        ->and($r->json('meta.per_page'))->toBe(100);
});

test('las cifras salen del dia entero y no de la pagina ni de la pestana', function () {
    // Al mediodía: «hace dos horas» tiene que caer HOY. Corrida pasada la
    // medianoche, caía ayer y la prueba fallaba según la hora del reloj.
    $this->travelTo(now()->setTime(12, 0));

    $c = conjuntoConPersonal(ComplexStaff::CELADOR);
    Sanctum::actingAs($c['user']);
    $hoy = now()->toDateString();

    // Tres domiciliarios que ya salieron: 10, 20 y 30 minutos adentro.
    $domi = repartidorConPedidoEn($c['complexId']);
    foreach ([10, 20, 30] as $i => $min) {
        entradaDelLibro($c['complexId'], [
            'kind' => 'domiciliario', 'domiciliary_id' => $domi->domiciliary_id,
            'method' => $i === 0 ? 'cedula' : 'codigo', 'visitor_name' => null,
            'created_at' => now()->subHours(2), 'exited_at' => now()->subHours(2)->addMinutes($min),
        ]);
    }
    // Dos visitas que siguen adentro y una de ayer que no cuenta.
    entradaDelLibro($c['complexId'], ['visitor_name' => 'Marta']);
    entradaDelLibro($c['complexId'], ['visitor_name' => 'Lucho', 'kind' => 'servicio']);
    entradaDelLibro($c['complexId'], ['created_at' => now()->subDay()]);

    $r = $this->getJson("/v1/conjunto/porteria/entradas?desde={$hoy}&hasta={$hoy}&per_page=1&kind=servicio")
        ->assertOk();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('meta.total'))->toBe(1)
        ->and($r->json('cifras'))->toBe([
            'total' => 5,
            'dentro' => 2,
            'domiciliarios' => 3,
            'por_cedula' => 1,
            'promedio_minutos' => 20,
        ])
        ->and($r->json('conteos.todas'))->toBe(5)
        ->and($r->json('conteos.dentro'))->toBe(2)
        ->and($r->json('conteos.domiciliario'))->toBe(3)
        ->and($r->json('conteos.visitante'))->toBe(1)
        ->and($r->json('conteos.servicio'))->toBe(1);
});

test('el buscador recorta las pestanas pero no la cabecera', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    entradaDelLibro($c['complexId'], ['visitor_name' => 'Marta Robles']);
    entradaDelLibro($c['complexId'], ['visitor_name' => 'Lucho Pérez']);

    $r = $this->getJson('/v1/conjunto/porteria/entradas?search=Marta')->assertOk();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('conteos.todas'))->toBe(1)
        ->and($r->json('cifras.total'))->toBe(2);
});

test('la pestana de siguen adentro filtra en el servidor', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    entradaDelLibro($c['complexId'], ['visitor_name' => 'Adentro']);
    entradaDelLibro($c['complexId'], ['visitor_name' => 'Salió', 'exited_at' => now()]);

    $r = $this->getJson('/v1/conjunto/porteria/entradas?dentro=1')->assertOk();

    expect(collect($r->json('data'))->pluck('visitor_name')->all())->toBe(['Adentro'])
        ->and($r->json('meta.total'))->toBe(1);
});

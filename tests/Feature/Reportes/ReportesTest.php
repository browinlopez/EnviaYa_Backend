<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LOS REPORTES
 *
 * Lo que se comprueba acá es lo que hace que un reporte sirva para decidir y no
 * solo para mirar: que la ventana sea la que se pidió, que la comparación sea
 * contra el periodo anterior de IGUAL tamaño, y que los filtros acoten de
 * verdad en vez de decorar la pantalla.
 */

function adminDeReportes(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => Area::NIVEL_GESTOR,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

/** Un pedido entregado, con lo justo para que las sumas cuadren. */
function pedidoDeReporte(int $negocio, string $fecha, int $total, int $domicilio = 5000): int
{
    return (int) DB::table('orderssales')->insertGetId([
        'busines_id'      => $negocio,
        'subtotal'        => $total - $domicilio,
        'domicilio'       => $domicilio,
        'domiciliary_fee' => (int) round($domicilio * 0.25),
        'discount'        => 0,
        'total'           => $total,
        'currency'        => 'COP',
        'sale_date'       => $fecha . ' 12:00:00',
        'state'           => 4,
        'payment_state'   => 'approved',
        'created_at'      => $fecha . ' 12:00:00',
        'updated_at'      => $fecha . ' 12:00:00',
    ], 'orderSales_id');
}

function negocioDeReporte(string $nombre, ?int $municipio = null, ?int $tipo = null): int
{
    return (int) DB::table('business')->insertGetId([
        'name'            => $nombre,
        'municipality_id' => $municipio,
        'type'            => $tipo,
        'state'           => 1,
        'qualification'   => 0,
    ], 'busines_id');
}

it('acepta un rango de fechas concreto y calcula el periodo anterior', function () {
    adminDeReportes();

    $r = $this->getJson('/v1/admin/reports/financial?from=2026-07-01&to=2026-07-31')
        ->assertOk()
        ->assertJsonPath('period.from', '2026-07-01')
        ->assertJsonPath('period.to', '2026-07-31')
        ->assertJsonPath('period.days', 31);

    // La ventana anterior tiene el MISMO tamaño y termina justo antes: sin eso
    // se estarían comparando 31 días contra 30 y la variación sería mentira.
    expect($r->json('period.previous.to'))->toBe('2026-06-30');
    expect($r->json('period.previous.from'))->toBe('2026-05-31');
});

it('endereza las fechas si vienen al revés', function () {
    adminDeReportes();

    // Es un error de dedo, no una consulta legítima: devolver un periodo vacío
    // haría pensar que no hubo ventas.
    $this->getJson('/v1/admin/reports/financial?from=2026-07-31&to=2026-07-01')
        ->assertOk()
        ->assertJsonPath('period.from', '2026-07-01')
        ->assertJsonPath('period.to', '2026-07-31');
});

it('compara contra el periodo anterior con cifras reales', function () {
    adminDeReportes();
    $negocio = negocioDeReporte('Tienda de prueba');

    // Dentro de los últimos 7 días.
    pedidoDeReporte($negocio, now()->subDays(1)->toDateString(), 100000);
    pedidoDeReporte($negocio, now()->subDays(3)->toDateString(), 50000);

    // En los 7 anteriores.
    pedidoDeReporte($negocio, now()->subDays(9)->toDateString(), 30000);

    $this->getJson('/v1/admin/reports/financial?range=7')
        ->assertOk()
        ->assertJsonPath('totals.orders', 2)
        ->assertJsonPath('totals.revenue', 150000)
        ->assertJsonPath('previous.orders', 1)
        ->assertJsonPath('previous.revenue', 30000);
});

it('el filtro por negocio acota de verdad y se declara en la respuesta', function () {
    adminDeReportes();

    $uno = negocioDeReporte('El Filtrado');
    $otro = negocioDeReporte('El Excluido');

    pedidoDeReporte($uno, now()->subDays(2)->toDateString(), 80000);
    pedidoDeReporte($otro, now()->subDays(2)->toDateString(), 900000);

    $r = $this->getJson("/v1/admin/reports/financial?range=30&business_id={$uno}")
        ->assertOk()
        ->assertJsonPath('totals.revenue', 80000);

    // Un reporte acotado que no dice que lo está es la forma más fácil de
    // sacar una conclusión equivocada: el panel pinta esto como una etiqueta.
    expect($r->json('filters'))->toHaveCount(1);
    expect($r->json('filters.0.label'))->toBe('Negocio');
    expect($r->json('filters.0.value'))->toBe('El Filtrado');
});

it('el filtro por municipio alcanza a través del negocio', function () {
    adminDeReportes();

    // La base de pruebas arranca sin catálogo geográfico: se crea el mínimo.
    $departamento = DB::table('departments')->insertGetId([
        'name'       => 'Atlántico',
        'country_id' => DB::table('countries')->insertGetId([
            'name'     => 'Colombia',
            'iso_code' => 'CO',
        ]),
    ]);

    $municipio = (int) DB::table('municipalities')->insertGetId([
        'name'          => 'Barranquilla',
        'department_id' => $departamento,
    ]);

    $dentro = negocioDeReporte('En el municipio', $municipio);
    $fuera  = negocioDeReporte('En otro lado', null);

    pedidoDeReporte($dentro, now()->subDays(2)->toDateString(), 40000);
    pedidoDeReporte($fuera, now()->subDays(2)->toDateString(), 700000);

    $this->getJson("/v1/admin/reports/financial?range=30&municipality_id={$municipio}")
        ->assertOk()
        ->assertJsonPath('totals.revenue', 40000);
});

it('el reporte por negocio ordena por ingreso y calcula la concentración', function () {
    adminDeReportes();

    $grande = negocioDeReporte('El que sostiene');
    $chico  = negocioDeReporte('El pequeño');

    pedidoDeReporte($grande, now()->subDays(2)->toDateString(), 300000);
    pedidoDeReporte($chico, now()->subDays(2)->toDateString(), 100000);

    $r = $this->getJson('/v1/admin/reports/businesses?range=30')->assertOk();

    $negocios = $r->json('businesses');
    expect($negocios[0]['name'])->toBe('El que sostiene');

    // 300.000 de 400.000. No es una cifra de logro sino de riesgo: si un solo
    // negocio hace tres cuartas partes, perderlo es perder tres cuartas partes.
    // Se comparan como número: `round()` de PHP devuelve 75 y 75.0 según el
    // caso, y afinar el tipo acá no prueba nada de la lógica.
    expect((float) $r->json('totals.top_share'))->toBe(75.0);
    expect((float) $r->json('totals.revenue'))->toBe(400000.0);
});

it('el reporte por negocio marca la tasa de cancelación', function () {
    adminDeReportes();
    $negocio = negocioDeReporte('Con cancelaciones');

    pedidoDeReporte($negocio, now()->subDays(2)->toDateString(), 100000);

    // Un cancelado del mismo periodo.
    DB::table('orderssales')->insert([
        'busines_id'    => $negocio,
        'subtotal'      => 50000,
        'domicilio'     => 5000,
        'total'         => 55000,
        'currency'      => 'COP',
        'sale_date'     => now()->subDays(2)->toDateString() . ' 12:00:00',
        'state'         => 5,
        'payment_state' => 'cancelled',
    ]);

    $r = $this->getJson('/v1/admin/reports/businesses?range=30')->assertOk();
    $fila = collect($r->json('businesses'))->firstWhere('name', 'Con cancelaciones');

    // Un negocio con mucha venta y un 50 % de cancelación no es un buen
    // negocio, y con solo la columna de ingresos lo parecía.
    expect((int) $fila['delivered'])->toBe(1);
    expect((int) $fila['cancelled'])->toBe(1);
    expect((float) $fila['cancel_rate'])->toBe(50.0);
    // El ingreso cuenta SOLO lo entregado: sumar lo cancelado infla la caja.
    expect((float) $fila['revenue'])->toBe(100000.0);
});

it('rechaza un tipo de reporte que no existe', function () {
    adminDeReportes();

    $this->getJson('/v1/admin/reports/inventado?range=30')
        ->assertNotFound()
        ->assertJsonPath('message', 'Tipo de reporte no válido.');
});

it('acota el rango para que nadie pida cinco años por error', function () {
    adminDeReportes();

    $this->getJson('/v1/admin/reports/financial?range=9999')
        ->assertOk()
        ->assertJsonPath('period.days', 365);
});

<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Filtrar por negocio en todo, y por conjunto en los reportes.
 *
 * El panel ya filtraba por negocio en órdenes, pagos, comprobantes y reportes,
 * pero no en productos, reseñas, domiciliarios ni liquidaciones — dos de esos
 * endpoints ni siquiera recibían `Request`.
 *
 * El de conjunto es distinto y merece cuidado: `orderssales` NO tiene columna
 * de conjunto, así que hay que llegar por el comprador. Se hace con EXISTS y
 * no con un join porque `buyer_complex` no tiene índice único: un comprador
 * con la pareja duplicada contaría su pedido dos veces y el total saldría
 * inflado.
 */

function staffReportes(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $u = User::factory()->create(['rol' => 4]);
    $u->area_id      = Area::where('code', 'sistema')->first()->id;
    $u->access_level = Area::NIVEL_GESTOR;
    $u->save();

    return $u;
}

function negocioParaFiltro(string $nombre): int
{
    return DB::table('business')->insertGetId([
        'name' => $nombre, 'qualification' => 0, 'state' => 1,
    ]);
}

function compradorDeConjunto(?int $complexId): int
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $u = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $u->user_id, 'qualification' => 0,
        'belongs_to_complex' => $complexId ? 1 : 0, 'state' => 1,
    ]);

    if ($complexId) {
        DB::table('buyer_complex')->insert([
            'buyer_id' => $buyerId, 'complex_id' => $complexId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $buyerId;
}

function pedidoParaFiltro(int $buyerId, int $businessId, float $total = 20000): int
{
    return DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'busines_id' => $businessId,
        'subtotal' => $total - 2000, 'domicilio' => 2000, 'total' => $total,
        'sale_date' => now(), 'state' => 4, 'payment_state' => 'paid',
    ], 'orderSales_id');
}

/* ---------------------------------------------------------------------- */

test('los reportes se pueden filtrar por conjunto', function () {
    $complexId = DB::table('residential_complexes')->insertGetId([
        'name' => 'Los Almendros', 'state' => 1, 'people_count' => 0,
    ], 'complex_id');

    $negocio = negocioParaFiltro('Tienda');

    pedidoParaFiltro(compradorDeConjunto($complexId), $negocio, 20000);
    pedidoParaFiltro(compradorDeConjunto(null), $negocio, 50000); // fuera del conjunto

    Sanctum::actingAs(staffReportes());

    $sinFiltro = $this->getJson('/v1/admin/reports/financial')->assertOk();
    expect((float) $sinFiltro->json('totals.revenue'))->toBe(70000.0);

    $conFiltro = $this->getJson("/v1/admin/reports/financial?complex_id={$complexId}")->assertOk();
    expect((float) $conFiltro->json('totals.revenue'))->toBe(20000.0);
});

test('el reporte dice que esta filtrado por conjunto', function () {
    // Un reporte filtrado que no lo dice es la forma más fácil de sacar una
    // conclusión equivocada.
    $complexId = DB::table('residential_complexes')->insertGetId([
        'name' => 'Los Almendros', 'state' => 1, 'people_count' => 0,
    ], 'complex_id');

    pedidoParaFiltro(compradorDeConjunto($complexId), negocioParaFiltro('Tienda'));

    Sanctum::actingAs(staffReportes());

    $this->getJson("/v1/admin/reports/financial?complex_id={$complexId}")
        ->assertOk()
        ->assertJsonPath('filters.0.label', 'Conjunto')
        ->assertJsonPath('filters.0.value', 'Los Almendros');
});

test('un comprador con el conjunto duplicado no cuenta dos veces', function () {
    // `buyer_complex` no tiene índice único. Con un join, este pedido se
    // contaría dos veces y el total saldría al doble.
    $complexId = DB::table('residential_complexes')->insertGetId([
        'name' => 'Los Almendros', 'state' => 1, 'people_count' => 0,
    ], 'complex_id');

    $buyerId = compradorDeConjunto($complexId);

    DB::table('buyer_complex')->insert([
        'buyer_id' => $buyerId, 'complex_id' => $complexId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    pedidoParaFiltro($buyerId, negocioParaFiltro('Tienda'), 20000);

    Sanctum::actingAs(staffReportes());

    expect((float) $this->getJson("/v1/admin/reports/financial?complex_id={$complexId}")
        ->json('totals.revenue'))->toBe(20000.0);
});

test('los productos se filtran por negocio', function () {
    $a = negocioParaFiltro('Tienda A');
    $b = negocioParaFiltro('Tienda B');

    $p = DB::table('products')->insertGetId(['name' => 'Arroz', 'state' => 1], 'products_id');

    foreach ([$a, $b] as $n) {
        DB::table('products_business')->insert([
            'busines_id' => $n, 'products_id' => $p, 'price' => 1000, 'amount' => 5,
        ]);
    }

    Sanctum::actingAs(staffReportes());

    $todos = $this->getJson('/v1/admin/products')->assertOk();
    expect(count($todos->json('data')))->toBe(2);

    $soloA = $this->getJson("/v1/admin/products?business_id={$a}")->assertOk();
    expect(count($soloA->json('data')))->toBe(1);
});

test('los domiciliarios se filtran por negocio', function () {
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $negocio = negocioParaFiltro('Tienda A');

    $conNegocio = DB::table('domiciliary')->insertGetId([
        'user_id' => User::factory()->create(['rol' => 3])->user_id,
        'available' => 1, 'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    DB::table('domiciliary')->insert([
        'user_id' => User::factory()->create(['rol' => 3])->user_id,
        'available' => 1, 'qualification' => 0, 'state' => 1,
    ]);

    DB::table('business_domiciliary')->insert([
        'busines_id' => $negocio, 'domiciliary_id' => $conNegocio, 'state' => 1,
    ]);

    Sanctum::actingAs(staffReportes());

    expect(count($this->getJson('/v1/admin/domiciliaries')->json()))->toBe(2);
    expect(count($this->getJson("/v1/admin/domiciliaries?business_id={$negocio}")->json()))->toBe(1);
});

test('el desglose dice que negocio aporta cuanto', function () {
    // Las tarjetas del panel eran callejones sin salida: decían el total y no
    // de dónde salía.
    $a = negocioParaFiltro('Tienda A');
    $b = negocioParaFiltro('Tienda B');

    pedidoParaFiltro(compradorDeConjunto(null), $a, 50000);
    pedidoParaFiltro(compradorDeConjunto(null), $b, 20000);

    Sanctum::actingAs(staffReportes());

    $r = $this->getJson('/v1/admin/reports-desglose?metric=revenue')->assertOk();

    expect($r->json('businesses.0.name'))->toBe('Tienda A')
        ->and((float) $r->json('businesses.0.revenue'))->toBe(50000.0)
        ->and((float) $r->json('businesses.1.revenue'))->toBe(20000.0);
});

test('el desglose respeta los mismos filtros que la tarjeta', function () {
    // Si el desglose no sumara lo que dice la tarjeta, sería peor que no
    // tenerlo.
    $complexId = DB::table('residential_complexes')->insertGetId([
        'name' => 'Los Almendros', 'state' => 1, 'people_count' => 0,
    ], 'complex_id');

    $negocio = negocioParaFiltro('Tienda');

    pedidoParaFiltro(compradorDeConjunto($complexId), $negocio, 20000);
    pedidoParaFiltro(compradorDeConjunto(null), $negocio, 50000);

    Sanctum::actingAs(staffReportes());

    expect((float) $this->getJson("/v1/admin/reports-desglose?metric=revenue&complex_id={$complexId}")
        ->json('businesses.0.revenue'))->toBe(20000.0);
});

test('una cifra sin desglose responde 404 y dice cuales hay', function () {
    Sanctum::actingAs(staffReportes());

    $this->getJson('/v1/admin/reports-desglose?metric=inventada')
        ->assertStatus(404)
        ->assertJsonStructure(['message', 'metrics']);
});

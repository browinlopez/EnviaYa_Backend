<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use App\Services\CuentaDelNegocio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 * LO QUE PASA EN EL MOSTRADOR DE VERDAD.
 *
 *  · El vecino entra y pide «$1.500 de queso y dos Pony»: la venta se anota,
 *    descuenta el inventario y entra en «Cuánto te queda», sin comisión.
 *  · «Hormiga culona, $3.000»: el tendero crea con nombre y precio lo que el
 *    catálogo no tiene, lo vende ya, y las demás tiendas no lo ven hasta que
 *    el equipo decide.
 */
function mostradorConQuesoYPony(): array
{
    Carbon::setTestNow('2026-11-24 12:00:00');
    $e = escenarioDeTope(null);

    $queso = DB::table('products')->insertGetId(['name' => 'Queso costeño', 'state' => 1], 'products_id');
    $pony = DB::table('products')->insertGetId(['name' => 'Pony Malta', 'state' => 1], 'products_id');

    DB::table('products_business')->insert([
        [
            'busines_id' => $e['businessId'], 'products_id' => $queso, 'price' => 20000,
            'amount' => 0, 'sale_unit' => 'kg', 'stock_weight' => 5, 'por_valor' => 1, 'costo' => 14000,
        ],
        [
            'busines_id' => $e['businessId'], 'products_id' => $pony, 'price' => 2500,
            'amount' => 10, 'sale_unit' => 'unidad', 'stock_weight' => 0, 'por_valor' => 0, 'costo' => 1800,
        ],
    ]);

    Sanctum::actingAs($e['tendero']);

    return $e + compact('queso', 'pony');
}

afterEach(fn () => Carbon::setTestNow());

it('«$1.500 de queso y dos Pony»: anota la venta, el cambio y descuenta el inventario', function () {
    $e = mostradorConQuesoYPony();

    $r = $this->postJson('/v1/negocio/ventas-mostrador', [
        'lineas' => [
            ['products_id' => $e['queso'], 'pedido_por' => 'valor', 'requested_value' => 1500],
            ['products_id' => $e['pony'], 'amount' => 2],
        ],
        'cash_tendered' => 10000,
    ])->assertCreated();

    expect($r->json('data.total'))->toEqual(6500)
        ->and($r->json('data.change_given'))->toEqual(3500);

    $queso = DB::table('products_business')->where('products_id', $e['queso'])->first();
    $pony = DB::table('products_business')->where('products_id', $e['pony'])->first();

    // 1.500 / 20.000 = 0,075 kg menos; 2 Pony menos.
    expect(round((float) $queso->stock_weight, 3))->toBe(4.925)
        ->and((int) $pony->amount)->toBe(8);
});

it('sin precio cambiado, manda el del producto', function () {
    $e = mostradorConQuesoYPony();

    $this->postJson('/v1/negocio/ventas-mostrador', [
        'lineas' => [['products_id' => $e['pony'], 'amount' => 1]],
    ])->assertCreated()->assertJsonPath('data.total', 2500);
});

it('con lo que paga no alcanza: no se anota nada', function () {
    $e = mostradorConQuesoYPony();

    $this->postJson('/v1/negocio/ventas-mostrador', [
        'lineas' => [['products_id' => $e['pony'], 'amount' => 2]],
        'cash_tendered' => 2000,
    ])->assertStatus(422);

    expect(DB::table('ventas_mostrador')->count())->toBe(0);
});

it('no se vende por valor lo que la tienda no marcó así', function () {
    $e = mostradorConQuesoYPony();
    DB::table('products_business')->where('products_id', $e['queso'])->update(['por_valor' => 0]);

    $this->postJson('/v1/negocio/ventas-mostrador', [
        'lineas' => [['products_id' => $e['queso'], 'pedido_por' => 'valor', 'requested_value' => 1500]],
    ])->assertStatus(422);
});

it('un producto de otra tienda no se vende acá', function () {
    $e = mostradorConQuesoYPony();
    $ajeno = DB::table('products')->insertGetId(['name' => 'Ajeno', 'state' => 1], 'products_id');

    $this->postJson('/v1/negocio/ventas-mostrador', [
        'lineas' => [['products_id' => $ajeno, 'amount' => 1]],
    ])->assertStatus(422);
});

it('la venta del mostrador entra en «Cuánto te queda», separada y sin comisión', function () {
    $e = mostradorConQuesoYPony();

    $this->postJson('/v1/negocio/ventas-mostrador', [
        'lineas' => [
            ['products_id' => $e['queso'], 'pedido_por' => 'valor', 'requested_value' => 1500],
            ['products_id' => $e['pony'], 'amount' => 2],
        ],
    ])->assertCreated();

    $c = app(CuentaDelNegocio::class)->para($e['businessId'], 'mes', now());

    // El pedido del escenario no está entregado: todo es del mostrador.
    expect($c['ventas_mostrador'])->toBe(6500.0)
        ->and($c['ventas_app'])->toBe(0.0)
        ->and($c['ventas'])->toBe(6500.0)
        // 0,075 kg × 14.000 + 2 × 1.800
        ->and($c['costo_de_lo_vendido'])->toBe(4650.0)
        ->and($c['comision'])->toBe(0.0)
        ->and($c['ventas_en_mostrador'])->toBe(1);
});

it('las ventas del día se listan con su total', function () {
    $e = mostradorConQuesoYPony();

    $this->postJson('/v1/negocio/ventas-mostrador', [
        'lineas' => [['products_id' => $e['pony'], 'amount' => 1]],
    ])->assertCreated();
    $this->postJson('/v1/negocio/ventas-mostrador', [
        'lineas' => [['products_id' => $e['pony'], 'amount' => 2]],
    ])->assertCreated();

    $this->getJson('/v1/negocio/ventas-mostrador')
        ->assertOk()
        ->assertJsonPath('data.total', 7500)
        ->assertJsonCount(2, 'data.ventas');
});

/* ---------------------------------------------------------------------- */
/* «HORMIGA CULONA, $3.000» */
/* ---------------------------------------------------------------------- */

it('con nombre y precio basta: queda en la tienda y se puede vender ya', function () {
    $e = mostradorConQuesoYPony();

    $r = $this->postJson('/v1/negocio/productos/propio', [
        'name' => 'Hormiga culona',
        'price' => 3000,
    ])->assertCreated();

    $id = $r->json('data.products_id');
    $producto = DB::table('products')->where('products_id', $id)->first();

    expect($producto->origen)->toBe('tendero')
        ->and((int) $producto->busines_id_propietario)->toBe($e['businessId'])
        ->and((bool) $producto->en_catalogo_general)->toBeFalse();

    // Y se vende en el mostrador sin más.
    $this->postJson('/v1/negocio/ventas-mostrador', [
        'lineas' => [['products_id' => $id, 'amount' => 1]],
    ])->assertCreated()->assertJsonPath('data.total', 3000);
});

it('el mismo nombre dos veces es un doble toque, no dos productos', function () {
    mostradorConQuesoYPony();

    $primero = $this->postJson('/v1/negocio/productos/propio', ['name' => 'Hormiga culona', 'price' => 3000]);
    $segundo = $this->postJson('/v1/negocio/productos/propio', ['name' => 'hormiga culona ', 'price' => 3000]);

    expect($segundo->json('data.products_id'))->toBe($primero->json('data.products_id'))
        ->and($segundo->json('data.ya_existia'))->toBeTrue();
});

it('otra tienda no lo encuentra al buscar hasta que el equipo lo pase al catálogo general', function () {
    $e = mostradorConQuesoYPony();
    $id = $this->postJson('/v1/negocio/productos/propio', ['name' => 'Hormiga culona', 'price' => 3000])
        ->json('data.products_id');

    // La dueña sí lo encuentra.
    expect(collect($this->getJson('/v1/negocio/catalogo?q=hormiga')->json('data'))->pluck('products_id'))
        ->toContain($id);

    // Otra tienda, no.
    $otra = escenarioDeTope(null);
    Sanctum::actingAs($otra['tendero']);
    expect(collect($this->getJson('/v1/negocio/catalogo?q=hormiga')->json('data'))->pluck('products_id'))
        ->not->toContain($id);

    // El equipo lo pasa al catálogo general y ya aparece.
    DB::table('products')->where('products_id', $id)->update(['en_catalogo_general' => 1]);
    expect(collect($this->getJson('/v1/negocio/catalogo?q=hormiga')->json('data'))->pluck('products_id'))
        ->toContain($id);
});

it('el panel lo ve en «Nuevos de tenderos» y la decisión lo saca de la bandeja', function () {
    $e = mostradorConQuesoYPony();
    $id = $this->postJson('/v1/negocio/productos/propio', ['name' => 'Hormiga culona', 'price' => 3000])
        ->json('data.products_id');

    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::factory()->create([
        'rol' => 4,
        'area_id' => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => 'gestor',
    ]);
    Sanctum::actingAs($admin);

    $bandeja = $this->getJson('/v1/admin/products?pendientes=1')->assertOk();
    expect(collect($bandeja->json('data'))->pluck('products_id'))->toContain($id)
        ->and($bandeja->json('pendientesDeTenderos'))->toBeGreaterThanOrEqual(1);

    // El equipo lo completa y decide.
    $this->putJson("/v1/admin/products/{$id}", ['brand' => 'Santandereana'])->assertOk();
    $this->postJson("/v1/admin/products/{$id}/catalogo-general", ['general' => true])->assertOk();

    $p = DB::table('products')->where('products_id', $id)->first();
    expect((bool) $p->en_catalogo_general)->toBeTrue()
        ->and($p->revisado_at)->not->toBeNull()
        ->and($p->brand)->toBe('Santandereana');

    expect(collect($this->getJson('/v1/admin/products?pendientes=1')->json('data'))->pluck('products_id'))
        ->not->toContain($id);
});

it('la lista de «Mis productos» lo trae marcado como propio', function () {
    $e = mostradorConQuesoYPony();
    $id = $this->postJson('/v1/negocio/productos/propio', ['name' => 'Hormiga culona', 'price' => 3000])
        ->json('data.products_id');

    $lista = $this->postJson('/v1/product/index', ['business_id' => $e['businessId']])->assertOk();
    $suyo = collect($lista->json())->firstWhere('product_id', $id);

    expect($suyo['propio'])->toBeTrue()
        ->and($suyo['en_revision'])->toBeTrue()
        ->and($suyo['sale_unit'])->toBe('unidad');

    // Y los del catálogo de siempre, no.
    expect(collect($lista->json())->firstWhere('product_id', $e['pony'])['propio'])->toBeFalse();
});

it('el precio que el tendero cambió en el mostrador manda en esa venta, y solo en esa', function () {
    $e = mostradorConQuesoYPony();

    $this->postJson('/v1/negocio/ventas-mostrador', [
        'lineas' => [['products_id' => $e['pony'], 'amount' => 2, 'price' => 2000]],
    ])->assertCreated()->assertJsonPath('data.total', 4000);

    // El precio del producto no se toca.
    expect((float) DB::table('products_business')->where('products_id', $e['pony'])->value('price'))->toBe(2500.0);
});

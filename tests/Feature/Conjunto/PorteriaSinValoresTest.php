<?php

use App\Models\Conjunto\ComplexStaff;
use App\Services\AccesoAlConjunto;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LA PORTERÍA NO VE CUÁNTO PAGÓ NADIE.
 *
 * Ni el celador ni el administrador del conjunto: del pedido se ve el
 * número, la tienda y la puerta. Ni el total ni el precio de lo que trae.
 */
function pswClavesDe(mixed $datos): array
{
    $claves = [];
    $iter = function ($d) use (&$iter, &$claves) {
        foreach ((array) $d as $k => $v) {
            $claves[] = $k;
            if (is_array($v)) {
                $iter($v);
            }
        }
    };
    $iter($datos);

    return array_unique(array_map('strval', $claves));
}

test('verificar no devuelve el total del pedido ni los precios de lo que trae', function () {
    $c = conjuntoConPersonal();
    $domi = repartidorConPedidoEn($c['complexId']);
    $codigo = app(AccesoAlConjunto::class)->generar($domi)['code'];

    Sanctum::actingAs($c['user']);

    $r = $this->postJson('/v1/conjunto/porteria/verificar', ['code' => $codigo])
        ->assertOk()
        ->assertJsonPath('allowed', true);

    $pedido = $r->json('orders.0');
    expect($pedido)->toHaveKeys(['orderSales_id', 'tower', 'apartment', 'business_name'])
        ->and($pedido)->not->toHaveKey('total');

    expect(pswClavesDe($r->json('orders')))
        ->not->toContain('total')
        ->not->toContain('unit_price')
        ->not->toContain('line_total');
});

test('la entrada se guarda sin el valor ni el contenido del pedido', function () {
    $c = conjuntoConPersonal();
    $domi = repartidorConPedidoEn($c['complexId']);
    $codigo = app(AccesoAlConjunto::class)->generar($domi)['code'];

    Sanctum::actingAs($c['user']);
    $this->postJson('/v1/conjunto/porteria/verificar', ['code' => $codigo])->assertOk();

    $guardado = json_decode((string) DB::table('complex_entries')->value('orders'), true);
    expect($guardado)->toHaveCount(1)
        ->and(array_keys($guardado[0]))->each->toBeIn(['orderSales_id', 'tower', 'apartment', 'business_name', 'business_logo'])
        ->and($guardado[0])->not->toHaveKey('total')
        ->and($guardado[0])->not->toHaveKey('items');
});

test('el libro de entradas no trae el total ni al celador ni al administrador', function (string $rol) {
    $c = conjuntoConPersonal($rol);
    $domi = repartidorConPedidoEn(null);

    // Una entrada vieja, guardada antes con el total, el medio de pago y el
    // contenido con precios.
    DB::table('complex_entries')->insert([
        'complex_id' => $c['complexId'], 'domiciliary_id' => $domi->domiciliary_id,
        'method' => 'codigo', 'orders_count' => 1,
        'orders' => json_encode([[
            'orderSales_id' => 77, 'total' => 82610, 'methods_id' => 1,
            'tower' => '2', 'apartment' => '301', 'business_name' => 'Tienda Doña Rosa',
            'items' => [['name' => 'Arroz', 'amount' => 2, 'unit_price' => 4500]],
        ]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs($c['user']);

    $r = $this->getJson('/v1/conjunto/porteria/entradas')->assertOk();
    $pedidos = $r->json('data.0.orders');

    // toEqual y no toBe: MySQL guarda el JSON con las claves en otro orden.
    expect($pedidos)->toEqual([[
        'orderSales_id' => 77, 'tower' => '2', 'apartment' => '301', 'business_name' => 'Tienda Doña Rosa',
    ]]);
    expect($r->getContent())->not->toContain('82610')
        ->not->toContain('unit_price');
})->with([
    'celador' => ComplexStaff::CELADOR,
    'administrador' => ComplexStaff::DUENO,
]);

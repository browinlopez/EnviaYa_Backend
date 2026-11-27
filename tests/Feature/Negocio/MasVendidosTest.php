<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LO MÁS VENDIDO: solo lo entregado, solo de esta tienda, y con lo facturado.
 */
function ventaDe(int $negocio, int $producto, float $cantidad, float $precio, int $estado = 4): void
{
    $id = DB::table('orderssales')->insertGetId([
        'busines_id' => $negocio, 'state' => $estado, 'total' => $cantidad * $precio, 'sale_date' => now()->subDay(),
    ], 'orderSales_id');
    DB::table('orderssales_detail')->insert([
        'orderSales_id' => $id, 'product_id' => $producto, 'amount' => $cantidad, 'unit_price' => $precio,
    ]);
}

test('ordena por unidades, cuenta solo lo entregado y no mezcla tiendas', function () {
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    $arroz = productoDeCatalogo('Arroz Diana', '7701', $t['categoria'])->products_id;
    $leche = productoDeCatalogo('Leche Alquería', '7702', $t['categoria'])->products_id;

    ventaDe($t['mio'], $arroz, 3, 4000);
    ventaDe($t['mio'], $arroz, 2, 4000);
    ventaDe($t['mio'], $leche, 4, 3000);
    ventaDe($t['mio'], $leche, 10, 3000, 5);      // cancelado: no cuenta
    ventaDe($t['vecino'], $leche, 50, 3000);      // de la vecina: no cuenta

    $p = $this->getJson('/v1/negocio/mas-vendidos?dias=30')->assertOk()->json('productos');

    expect($p)->toHaveCount(2)
        ->and($p[0]['nombre'])->toBe('Arroz Diana')
        ->and($p[0]['unidades'])->toBe(5)
        ->and($p[0]['facturado'])->toBe(20000)
        ->and($p[0]['pedidos'])->toBe(2)
        ->and($p[1]['unidades'])->toBe(4);
});

/*
 * EL CALENDARIO DEL PANEL: entre dos fechas, además de `dias`. La app sigue
 * mandando `dias` y tiene que seguir funcionando igual.
 */
function ventaEl(int $negocio, int $producto, float $cantidad, string $cuando): void
{
    $id = DB::table('orderssales')->insertGetId([
        'busines_id' => $negocio, 'state' => 4, 'total' => $cantidad * 1000, 'sale_date' => $cuando,
    ], 'orderSales_id');
    DB::table('orderssales_detail')->insert([
        'orderSales_id' => $id, 'product_id' => $producto, 'amount' => $cantidad, 'unit_price' => 1000,
    ]);
}

test('entre dos fechas cuenta solo ese rango, con los dos días enteros', function () {
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);
    $gasas = productoDeCatalogo('Gasas estériles', '7703', $t['categoria'])->products_id;

    ventaEl($t['mio'], $gasas, 1, '2026-10-31 23:59:00'); // antes
    ventaEl($t['mio'], $gasas, 2, '2026-11-01 00:05:00'); // dentro
    ventaEl($t['mio'], $gasas, 3, '2026-11-07 23:58:00'); // dentro, el último día
    ventaEl($t['mio'], $gasas, 7, '2026-11-08 00:01:00'); // después

    $r = $this->getJson('/v1/negocio/mas-vendidos?desde=2026-11-01&hasta=2026-11-07')->assertOk();

    expect($r->json('desde'))->toBe('2026-11-01')
        ->and($r->json('hasta'))->toBe('2026-11-07')
        ->and($r->json('dias'))->toBe(7)
        ->and($r->json('productos.0.unidades'))->toBe(5);
});

test('dias sigue funcionando y dice su rango', function () {
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);
    $gasas = productoDeCatalogo('Gasas estériles', '7703', $t['categoria'])->products_id;
    ventaEl($t['mio'], $gasas, 2, now()->subDays(3)->toDateTimeString());

    $r = $this->getJson('/v1/negocio/mas-vendidos?dias=7')->assertOk();

    expect($r->json('dias'))->toBe(7)
        ->and($r->json('hasta'))->toBe(now()->toDateString())
        ->and($r->json('productos.0.unidades'))->toBe(2);
});

test('un rango al revés, a medias o de más de un año es un 422 que dice el campo', function () {
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    $this->getJson('/v1/negocio/mas-vendidos?desde=2026-11-10&hasta=2026-11-01')
        ->assertStatus(422)->assertJsonValidationErrors('hasta');
    $this->getJson('/v1/negocio/mas-vendidos?desde=2026-11-10')
        ->assertStatus(422)->assertJsonValidationErrors('hasta');
    $this->getJson('/v1/negocio/mas-vendidos?desde=2024-01-01&hasta=2026-11-01')
        ->assertStatus(422)->assertJsonValidationErrors('hasta');
    $this->getJson('/v1/negocio/mas-vendidos?desde=ayer&hasta=2026-11-01')
        ->assertStatus(422)->assertJsonValidationErrors('desde');
});

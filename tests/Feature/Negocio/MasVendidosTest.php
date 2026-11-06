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

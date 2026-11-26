<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * El tablero del panel por periodo: un pedido abierto de la semana pasada no
 * se acumula en el «hoy», y sin periodo todo sigue como antes (la app).
 */
function negocioConPedidos(): array
{
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $user = User::factory()->create(['rol' => 2]);
    $owner = DB::table('owner')->insertGetId(['user_id' => $user->user_id, 'state' => 1], 'owner_id');
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId(['name' => 'La Mía', 'qualification' => 0, 'state' => 1, 'type' => $tipo], 'busines_id');
    DB::table('owner_busines')->insert(['owner_id' => $owner, 'busines_id' => $negocio, 'state' => 1]);

    $comprador = User::factory()->create(['rol' => 1]);
    $buyer = DB::table('buyer')->insertGetId(['user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1]);
    $pedido = fn ($fecha) => DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyer, 'busines_id' => $negocio, 'subtotal' => 10000, 'domicilio' => 0,
        'total' => 10000, 'sale_date' => $fecha, 'state' => 1, 'payment_state' => 'paid',
    ], 'orderSales_id');

    return [
        'user' => $user,
        'negocio' => $negocio,
        'hoy' => $pedido(now()),
        'viejo' => $pedido(now()->subMonths(2)),
    ];
}

test('con periodo hoy, el pedido abierto de hace dos meses no sale', function () {
    $n = negocioConPedidos();
    Sanctum::actingAs($n['user']);

    $ids = collect($this->getJson('/v1/negocio/pedidos?periodo=hoy')->assertOk()->json('orders'))
        ->pluck('order_id')->all();

    expect($ids)->toContain($n['hoy'])->not->toContain($n['viejo']);
});

test('sin periodo, como siempre: todo lo abierto', function () {
    $n = negocioConPedidos();
    Sanctum::actingAs($n['user']);

    $ids = collect($this->getJson('/v1/negocio/pedidos')->assertOk()->json('orders'))
        ->pluck('order_id')->all();

    expect($ids)->toContain($n['hoy'])->toContain($n['viejo']);
});

test('un periodo que no existe se rechaza', function () {
    $n = negocioConPedidos();
    Sanctum::actingAs($n['user']);

    $this->getJson('/v1/negocio/pedidos?periodo=siglo')->assertStatus(422);
});

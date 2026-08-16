<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Paginación en servidor de los listados que crecen sin techo.
 *
 * Es opcional a propósito: sin parámetros el endpoint responde completo, como
 * siempre. Eso permite que las pantallas se migren una a una en vez de romper
 * hoy los indicadores de Órdenes, que se calculan sobre lo filtrado en el
 * navegador.
 */

function adminSistema(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
    $sistema = Area::where('code', 'sistema')->firstOrFail();

    $u = User::factory()->create([
        'rol' => 4, 'area_id' => $sistema->id, 'access_level' => 'gestor',
    ]);
    Sanctum::actingAs($u);

    return $u;
}

/** Crea N pagos con su pedido, para tener algo que paginar. */
function sembrarPagos(int $n): void
{
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda P', 'qualification' => 0, 'state' => 1,
    ]);

    for ($i = 1; $i <= $n; $i++) {
        $orden = DB::table('orderssales')->insertGetId([
            'busines_id' => $negocio,
            'total'      => 1000 * $i,
            'subtotal'   => 1000 * $i,
            'domicilio'  => 0,
            'state'      => 1,
            'sale_date'  => now()->subDays($i),
        ], 'orderSales_id');

        DB::table('payments')->insert([
            'orderSales_id' => $orden,
            'amount'        => 1000 * $i,
            'total'         => 1000 * $i,
            'payment_status' => 'approved',
            'status'        => 'approved',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }
}

test('sin parámetros el listado responde completo, como antes', function () {
    adminSistema();
    sembrarPagos(30);

    $r = $this->getJson('/v1/admin/payments')->assertOk();

    // `meta` en null es la señal de "esto no está paginado": el panel la usa
    // para saber si debe dibujar el paginador.
    expect($r->json('meta'))->toBeNull()
        ->and($r->json('data'))->toHaveCount(30);
});

test('con page y per_page corta y reporta el total real', function () {
    adminSistema();
    sembrarPagos(30);

    $r = $this->getJson('/v1/admin/payments?page=1&per_page=10')->assertOk();

    expect($r->json('data'))->toHaveCount(10)
        // El total es el de la BASE, no el de lo descargado: es lo que evita
        // que la pantalla diga "10 registros" cuando hay 30.
        ->and($r->json('meta.total'))->toBe(30)
        ->and($r->json('meta.last_page'))->toBe(3)
        ->and($r->json('meta.page'))->toBe(1);

    $ultima = $this->getJson('/v1/admin/payments?page=3&per_page=10')->assertOk();
    expect($ultima->json('data'))->toHaveCount(10);
});

test('el tamaño de página tiene techo duro', function () {
    adminSistema();
    sembrarPagos(5);

    // Aunque el cliente pida 100000, no se sirve más que el máximo.
    $r = $this->getJson('/v1/admin/payments?page=1&per_page=100000')->assertOk();

    expect($r->json('meta.per_page'))->toBe(200);
});

test('la búsqueda filtra ANTES de contar', function () {
    adminSistema();

    $a = DB::table('business')->insertGetId(['name' => 'Panadería Luna', 'qualification' => 0, 'state' => 1]);
    $b = DB::table('business')->insertGetId(['name' => 'Ferretería Sol', 'qualification' => 0, 'state' => 1]);

    foreach ([$a, $a, $b] as $negocio) {
        $orden = DB::table('orderssales')->insertGetId([
            'busines_id' => $negocio, 'total' => 1000, 'subtotal' => 1000,
            'domicilio' => 0, 'state' => 1, 'sale_date' => now(),
        ], 'orderSales_id');

        DB::table('payments')->insert([
            'orderSales_id' => $orden, 'amount' => 1000, 'total' => 1000, 'payment_status' => 'approved', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $r = $this->getJson('/v1/admin/payments?page=1&per_page=25&search=Luna')->assertOk();

    // Si el total fuera el de la tabla sin filtrar, el paginador ofrecería
    // páginas para filas que la búsqueda ya descartó.
    expect($r->json('meta.total'))->toBe(2)
        ->and($r->json('data'))->toHaveCount(2);
});

test('solo se ordena por las columnas declaradas', function () {
    adminSistema();
    sembrarPagos(5);

    // Una columna que no está en la lista blanca se ignora y se cae al orden
    // por defecto. Pasar lo que mande el cliente directo a `orderBy` sería
    // inyección: llega como identificador y el ligado de parámetros no lo cubre.
    $r = $this->getJson('/v1/admin/payments?page=1&per_page=5&sort=DROP TABLE&dir=asc')
        ->assertOk();

    expect($r->json('data'))->toHaveCount(5);
});

test('los pedidos aceptan los mismos parámetros', function () {
    adminSistema();
    sembrarPagos(12);

    $r = $this->getJson('/v1/admin/orders?page=2&per_page=5')->assertOk();

    expect($r->json('data'))->toHaveCount(5)
        ->and($r->json('meta.total'))->toBe(12)
        ->and($r->json('meta.page'))->toBe(2);
});

<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LOS LISTADOS QUE CRECEN SIN TECHO
 *
 * Lo que se comprueba no es que "haya paginación" sino lo que la hace correcta:
 * que el TOTAL sea el del conjunto filtrado y no el de la tabla, y que los
 * INDICADORES salgan de todo lo filtrado y no de la página servida.
 *
 * Esto último es la razón por la que la paginación estuvo meses disponible y
 * sin usar: sumar en el navegador lo que le llegaba habría dejado "ingresos del
 * periodo" mostrando el total de 25 pedidos. En una pantalla de dinero eso no
 * es un dato incompleto, es uno falso.
 */

function adminPaginado(): User
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

function negocioPaginado(string $nombre = 'Tienda'): int
{
    return (int) DB::table('business')->insertGetId([
        'name' => $nombre, 'state' => 1, 'qualification' => 0,
    ], 'busines_id');
}

function pedidoPaginado(int $negocio, int $estado, int $total, int $diasAtras = 1): int
{
    return (int) DB::table('orderssales')->insertGetId([
        'busines_id'      => $negocio,
        'subtotal'        => $total - 5000,
        'domicilio'       => 5000,
        'domiciliary_fee' => 1250,
        'total'           => $total,
        'currency'        => 'COP',
        'sale_date'       => now()->subDays($diasAtras),
        'state'           => $estado,
        'payment_state'   => 'approved',
        'created_at'      => now()->subDays($diasAtras),
        'updated_at'      => now()->subDays($diasAtras),
    ], 'orderSales_id');
}

it('sin pedir página devuelve el listado completo, como antes', function () {
    adminPaginado();
    $n = negocioPaginado();

    foreach (range(1, 30) as $i) {
        pedidoPaginado($n, 4, 10000);
    }

    // Compatibilidad deliberada: hay pantallas que todavía no migraron, y
    // cambiarles la forma de la respuesta las rompería a todas de golpe.
    $r = $this->getJson('/v1/admin/orders')->assertOk();

    expect($r->json('meta'))->toBeNull();
    expect($r->json('data'))->toHaveCount(30);
});

it('sirve una sola página y dice cuántas hay', function () {
    adminPaginado();
    $n = negocioPaginado();

    foreach (range(1, 30) as $i) {
        pedidoPaginado($n, 4, 10000);
    }

    $this->getJson('/v1/admin/orders?page=1&per_page=10')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 30)
        ->assertJsonPath('meta.last_page', 3);
});

it('los indicadores son del conjunto, no de la página', function () {
    adminPaginado();
    $n = negocioPaginado();

    foreach (range(1, 30) as $i) {
        pedidoPaginado($n, 4, 10000);
    }

    $r = $this->getJson('/v1/admin/orders?page=1&per_page=5')->assertOk();

    // Cinco filas en la mano, treinta en la cuenta. Si el resumen saliera de la
    // página, "ingresos" diría 50.000 en vez de 300.000.
    expect($r->json('data'))->toHaveCount(5);
    expect($r->json('summary.total'))->toBe(30);
    expect((float) $r->json('summary.ingresos'))->toBe(300000.0);
});

it('el total respeta la búsqueda, no el tamaño de la tabla', function () {
    adminPaginado();

    $uno  = negocioPaginado('Panadería Central');
    $otro = negocioPaginado('Ferretería Norte');

    foreach (range(1, 12) as $i) {
        pedidoPaginado($uno, 4, 10000);
    }
    foreach (range(1, 8) as $i) {
        pedidoPaginado($otro, 4, 10000);
    }

    // Con el total sin filtrar, el panel dibujaría paginación para filas que la
    // búsqueda ya descartó.
    $this->getJson('/v1/admin/orders?page=1&per_page=5&search=Panader')
        ->assertOk()
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('summary.total', 12);
});

it('el filtro por negocio alcanza a los indicadores', function () {
    adminPaginado();

    $uno  = negocioPaginado('El Filtrado');
    $otro = negocioPaginado('El Excluido');

    pedidoPaginado($uno, 4, 80000);
    pedidoPaginado($otro, 4, 900000);

    $r = $this->getJson("/v1/admin/orders?page=1&per_page=10&business_id={$uno}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    expect((float) $r->json('summary.ingresos'))->toBe(80000.0);
});

it('cuenta como "requieren atención" los que llevan demasiado sin avanzar', function () {
    adminPaginado();
    $n = negocioPaginado();

    // Listo para recoger, sin repartidor y de hace dos días: atascado.
    pedidoPaginado($n, 2, 10000, 2);
    // Recién creado y en curso: no.
    pedidoPaginado($n, 1, 10000, 0);
    // Entregado: no cuenta aunque sea viejo.
    pedidoPaginado($n, 4, 10000, 30);

    $r = $this->getJson('/v1/admin/orders?page=1&per_page=10')->assertOk();
    expect($r->json('summary.atencion'))->toBe(1);

    // Y el filtro devuelve exactamente ese.
    $this->getJson('/v1/admin/orders?page=1&per_page=10&state_group=atencion')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('el tope por página no se puede saltar', function () {
    adminPaginado();
    $n = negocioPaginado();

    foreach (range(1, 5) as $i) {
        pedidoPaginado($n, 4, 10000);
    }

    // Pedir 100000 no sirve de nada: el techo lo pone el servidor.
    $this->getJson('/v1/admin/orders?page=1&per_page=100000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 200);
});

it('no se puede ordenar por una columna no declarada', function () {
    adminPaginado();
    $n = negocioPaginado();
    pedidoPaginado($n, 4, 10000);

    // La columna llega como identificador y no como valor, así que el ligado de
    // parámetros no la protege: solo la lista blanca.
    $this->getJson('/v1/admin/orders?page=1&per_page=5&sort=(SELECT 1)')
        ->assertOk()
        ->assertJsonPath('meta.sort', '(SELECT 1)');
});

it('usuarios pagina y cuenta por rol sobre todo el padrón', function () {
    adminPaginado();
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    User::factory()->count(12)->create(['rol' => 1]);

    $r = $this->getJson('/v1/admin/users?page=1&per_page=5')->assertOk();

    expect($r->json('data'))->toHaveCount(5);
    // 12 compradores + el administrador de la prueba.
    expect($r->json('summary.total'))->toBe(13);
    expect($r->json('summary.compradores'))->toBe(12);
});

it('el promedio de reseñas queda indefinido cuando no hay ninguna', function () {
    adminPaginado();

    $r = $this->getJson('/v1/admin/reviews?scope=business&page=1&per_page=5')
        ->assertOk();

    // `null`, no 0: un promedio de cero estrellas se lee como "todo el mundo la
    // odia", cuando lo que pasa es que nadie ha opinado.
    expect($r->json('summary.total'))->toBe(0);
    expect($r->json('summary.promedio'))->toBeNull();
});

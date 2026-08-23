<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
 * Un tendero con su tienda, la del vecino y una categoria.
 *
 * Vive aca y no en un archivo de pruebas porque lo usan dos —el catalogo y la
 * carga masiva— y Pest solo carga los archivos que va a correr: con el helper
 * dentro de uno de ellos, correr el otro solo se cae con
 * `Call to undefined function`.
 */
function tenderoConCatalogo(): array
{
    \App\Models\Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);

    $user = \App\Models\User::factory()->create(['rol' => 2]);

    $ownerId = \Illuminate\Support\Facades\DB::table('owner')->insertGetId(['user_id' => $user->user_id, 'state' => 1], 'owner_id');

    $tipo = \Illuminate\Support\Facades\DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $mio = \Illuminate\Support\Facades\DB::table('business')->insertGetId([
        'name' => 'La Esquina', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    \Illuminate\Support\Facades\DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $mio, 'state' => 1]);

    $vecino = \Illuminate\Support\Facades\DB::table('business')->insertGetId([
        'name' => 'La de Enfrente', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $categoria = \Illuminate\Support\Facades\DB::table('category')->insertGetId(['name' => 'Abarrotes'], 'category_id');

    return compact('user', 'mio', 'vecino', 'categoria');
}

function productoDeCatalogo(string $nombre, ?string $barcode = null, ?int $categoria = null): \App\Models\Product\Product
{
    return \App\Models\Product\Product::create([
        'name' => $nombre, 'barcode' => $barcode, 'category_id' => $categoria,
        'state' => 1, 'origen' => 'equipo',
    ]);
}

function enLaTienda(int $businessId, int $productId, float $precio = 5000, int $cantidad = 3): void
{
    \Illuminate\Support\Facades\DB::table('products_business')->insert([
        'busines_id' => $businessId, 'products_id' => $productId,
        'price' => $precio, 'amount' => $cantidad, 'qualification' => 0,
    ]);
}

/*
 * Un pedido aceptado, su tienda, su dueno y un domiciliario con cupo.
 *
 * Compartido entre el tope de efectivo y el candado del pedido. Vive aca
 * porque Pest solo carga los archivos que va a correr: con el helper dentro de
 * uno de ellos, correr el otro solo se cae con Call to undefined function.
 */
function escenarioDeTope(?float $tope, int $metodo = 1, int $total = 60000): array
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $nombre) {
        \App\Models\Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    // `orderssales.methods_id` es clave foránea: sin la fila, el insert falla.
    foreach ([1 => 'Efectivo', 2 => 'Tarjeta'] as $id => $nombre) {
        \Illuminate\Support\Facades\DB::table('payment_methods')->insertOrIgnore([
            'methods_id' => $id, 'name' => $nombre, 'state' => 1,
        ]);
    }

    $repartidor = \App\Models\User::factory()->create(['rol' => 3]);
    $domiId = \Illuminate\Support\Facades\DB::table('domiciliary')->insertGetId([
        'user_id' => $repartidor->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    $tendero = \App\Models\User::factory()->create(['rol' => 2]);

    // `business.type` es clave foránea a `category_business`.
    $tipo = \Illuminate\Support\Facades\DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $businessId = \Illuminate\Support\Facades\DB::table('business')->insertGetId([
        'name' => 'Tienda', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        'max_courier_cash' => $tope,
    ], 'busines_id');

    $ownerId = \Illuminate\Support\Facades\DB::table('owner')->insertGetId([
        'user_id' => $tendero->user_id, 'state' => 1,
    ], 'owner_id');

    \Illuminate\Support\Facades\DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $businessId, 'state' => 1,
    ]);

    \Illuminate\Support\Facades\DB::table('business_domiciliary')->insert([
        'busines_id' => $businessId, 'domiciliary_id' => $domiId, 'state' => 1,
    ]);

    $comprador = \App\Models\User::factory()->create(['rol' => 1]);
    $buyerId = \Illuminate\Support\Facades\DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    // Aceptado y esperando a que alguien lo lleve.
    $orderId = \Illuminate\Support\Facades\DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'busines_id' => $businessId,
        'methods_id' => $metodo,
        'subtotal' => $total - 2000, 'domicilio' => 2000, 'total' => $total,
        'domiciliary_fee' => 500, 'sale_date' => now(),
        'state' => 2, 'payment_state' => $metodo === 1 ? 'pending_cash' : 'paid',
    ], 'orderSales_id');

    return compact('repartidor', 'domiId', 'tendero', 'businessId', 'orderId');
}

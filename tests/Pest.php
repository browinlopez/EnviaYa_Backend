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

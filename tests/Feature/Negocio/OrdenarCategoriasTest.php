<?php

use App\Support\CategoriaDelNegocio;
use Illuminate\Support\Facades\DB;

/*
 * Un producto compartido se enseña con la categoría de cada oficio: en la
 * droguería «Gripa y tos», en la tienda «Botiquín y medicamentos».
 */
function oficiosYCategorias(): array
{
    DB::table('category_business')->insert([['id' => 1, 'name' => 'Tienda'], ['id' => 2, 'name' => 'Farmacia']]);
    $cat = fn ($n, $t) => DB::table('category')->insertGetId(['name' => $n, 'state' => 1, 'business_category_id' => $t], 'category_id');

    return [
        'gripa' => $cat('Gripa y tos', 2),
        'medicina' => $cat('Medicina', 2),
        'botiquin' => $cat('Botiquín y medicamentos', 1),
        'tienda' => DB::table('business')->insertGetId(['name' => 'La esquina', 'qualification' => 0, 'state' => 1, 'type' => 1], 'busines_id'),
        'drogueria' => DB::table('business')->insertGetId(['name' => 'Droguería', 'qualification' => 0, 'state' => 1, 'type' => 2], 'busines_id'),
    ];
}

test('en la tienda, un producto de droguería pasa a la categoría de tienda', function () {
    $o = oficiosYCategorias();
    // 562 es el Vick VapoRub de la lista revisada: va a «Gripa y tos».
    DB::table('products')->insert(['products_id' => 562, 'name' => 'VICK VAPORUB', 'state' => 1, 'category_id' => $o['medicina']]);
    foreach ([$o['tienda'], $o['drogueria']] as $b) {
        DB::table('products_business')->insert(['busines_id' => $b, 'products_id' => 562, 'price' => 5000]);
    }

    $this->artisan('catalogo:ordenar-categorias', ['--aplicar' => true])->assertSuccessful();

    expect(DB::table('products')->where('products_id', 562)->value('category_id'))->toBe($o['gripa']);
    expect(DB::table('products_business')->where('busines_id', $o['tienda'])->value('category_id'))->toBe($o['botiquin']);
    // La droguería no cambia: usa la del producto.
    expect(DB::table('products_business')->where('busines_id', $o['drogueria'])->value('category_id'))->toBeNull();

    CategoriaDelNegocio::olvidar();
    expect(CategoriaDelNegocio::de($o['botiquin'], $o['gripa'])['name'])->toBe('Botiquín y medicamentos');
    expect(CategoriaDelNegocio::de(null, $o['gripa'])['name'])->toBe('Gripa y tos');
});

test('los alimentos salen de la droguería y siguen en la tienda', function () {
    $o = oficiosYCategorias();
    DB::table('products')->insert(['products_id' => 6784, 'name' => 'Quesitos light', 'state' => 1, 'category_id' => $o['medicina']]);
    foreach ([$o['tienda'], $o['drogueria']] as $b) {
        DB::table('products_business')->insert(['busines_id' => $b, 'products_id' => 6784, 'price' => 5000]);
    }

    $this->artisan('catalogo:ordenar-categorias')->assertSuccessful();
    expect(DB::table('products_business')->where('products_id', 6784)->count())->toBe(2);

    $this->artisan('catalogo:ordenar-categorias', ['--aplicar' => true])->assertSuccessful();
    expect(DB::table('products_business')->where('products_id', 6784)->pluck('busines_id')->all())->toBe([$o['tienda']]);
});

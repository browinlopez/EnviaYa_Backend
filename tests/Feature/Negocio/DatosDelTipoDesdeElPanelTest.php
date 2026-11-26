<?php

use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Un restaurante lleva su carta completa desde el panel.
 *
 * Los platos de un restaurante no los vende nadie más: sus alérgenos, sus
 * minutos de preparación y su foto los pone él. Lo del catálogo compartido
 * (lo que venden varias tiendas) sigue sin tocarse desde una sola.
 */
function restauranteDelPanel(string $nombre = 'Sabor Costeño'): array
{
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    $user = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(['user_id' => $user->user_id, 'state' => 1], 'owner_id');

    // El tipo 3 es «restaurante» en ProductTypeSchema.
    foreach ([1 => 'Tienda', 2 => 'Droguería', 3 => 'Restaurante', 4 => 'Repuestos'] as $id => $n) {
        DB::table('category_business')->updateOrInsert(['id' => $id], ['name' => $n]);
    }

    $negocio = DB::table('business')->insertGetId([
        'name' => $nombre, 'qualification' => 0, 'state' => 1, 'type' => 3,
    ], 'busines_id');
    DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1]);

    return ['user' => $user, 'negocio' => $negocio];
}

function platoEn(int $negocio, string $nombre): int
{
    $categoria = DB::table('category')->insertGetId(['name' => 'Almuerzos', 'state' => 1], 'category_id');
    $p = Product::create(['name' => $nombre, 'description' => '', 'category_id' => $categoria, 'state' => 1]);
    ProductBusiness::create([
        'busines_id' => $negocio, 'products_id' => $p->products_id,
        'price' => 20000, 'amount' => 10, 'qualification' => 0,
    ]);

    return $p->products_id;
}

test('un restaurante le pone a su plato los datos de restaurante y los comunes', function () {
    $r = restauranteDelPanel();
    $id = platoEn($r['negocio'], 'Mondongo');
    Sanctum::actingAs($r['user']);

    $this->putJson("/v1/negocio/productos/{$id}", [
        'minutos_preparacion' => 25,
        'acompanamientos' => 'Arroz y aguacate',
        'is_vegan' => false,
        'is_gluten_free' => true,
        'allergens' => 'Ninguno',
        'presentation' => 'Plato hondo',
    ], ['X-Negocio' => $r['negocio']])->assertOk();

    $fila = DB::table('restaurant_products')->where('products_id', $id)->first();
    expect($fila->minutos_preparacion)->toBe(25)
        ->and($fila->acompanamientos)->toBe('Arroz y aguacate')
        ->and((bool) $fila->is_gluten_free)->toBeTrue()
        ->and(Product::find($id)->presentation)->toBe('Plato hondo');

    // Y el listado del panel los devuelve crudos, para volver a editarlos.
    $lista = $this->getJson('/v1/negocio/productos', ['X-Negocio' => $r['negocio']])->assertOk();
    expect($lista->json('data.0.datos_del_tipo.minutos_preparacion'))->toBe(25)
        ->and($lista->json('data.0.datos_del_tipo.presentation'))->toBe('Plato hondo');
});

test('lo que venden otros negocios no se le cambian los datos del tipo', function () {
    $r = restauranteDelPanel();
    $otro = restauranteDelPanel('El Vecino');
    $id = platoEn($r['negocio'], 'Gaseosa personal');
    ProductBusiness::create([
        'busines_id' => $otro['negocio'], 'products_id' => $id,
        'price' => 3500, 'amount' => 5, 'qualification' => 0,
    ]);
    Sanctum::actingAs($r['user']);

    $this->putJson("/v1/negocio/productos/{$id}", ['allergens' => 'Azúcar'], ['X-Negocio' => $r['negocio']])
        ->assertStatus(422);

    expect(DB::table('restaurant_products')->where('products_id', $id)->exists())->toBeFalse();

    // Ni la foto.
    $this->postJson("/v1/negocio/productos/{$id}/foto", [], ['X-Negocio' => $r['negocio']])
        ->assertStatus(422);
});

test('un dato con la forma equivocada se rechaza junto a su campo', function () {
    $r = restauranteDelPanel();
    $id = platoEn($r['negocio'], 'Sancocho');
    Sanctum::actingAs($r['user']);

    $this->putJson("/v1/negocio/productos/{$id}", ['minutos_preparacion' => 'mucho'], ['X-Negocio' => $r['negocio']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('minutos_preparacion');
});

test('un restaurante arma las presentaciones de su plato', function () {
    $r = restauranteDelPanel();
    $id = platoEn($r['negocio'], 'Sancocho de gallina');
    Sanctum::actingAs($r['user']);
    $h = ['X-Negocio' => $r['negocio']];

    $this->putJson("/v1/negocio/productos/{$id}", ['allergens' => 'Ninguno'], $h)->assertOk();

    $nueva = $this->postJson("/v1/negocio/productos/{$id}/variantes", [
        'variante' => 'Familiar', 'price' => 45000, 'variante_actual' => 'Personal',
    ], $h)->assertCreated()->json('data.products_id');

    $base = Product::find($id);
    $copia = Product::find($nueva);
    expect($base->group_id)->not->toBeNull()
        ->and($copia->group_id)->toBe($base->group_id)
        ->and($base->variante)->toBe('Personal')
        ->and($copia->variante)->toBe('Familiar')
        ->and($copia->name)->toBe('Sancocho de gallina Familiar')
        ->and(DB::table('restaurant_products')->where('products_id', $nueva)->value('allergens'))->toBe('Ninguno')
        ->and((float) ProductBusiness::where('products_id', $nueva)->value('price'))->toBe(45000.0);

    // La misma etiqueta dos veces, no.
    $this->postJson("/v1/negocio/productos/{$id}/variantes", ['variante' => 'familiar', 'price' => 1], $h)
        ->assertStatus(422)->assertJsonValidationErrors('variante');

    // El listado trae el grupo para juntarlas.
    $lista = collect($this->getJson('/v1/negocio/productos', $h)->json('data'));
    expect($lista->where('group_id', $base->group_id)->count())->toBe(2);
});

test('a lo que venden otros negocios no se le arman presentaciones', function () {
    $r = restauranteDelPanel();
    $otro = restauranteDelPanel('El Vecino');
    $id = platoEn($r['negocio'], 'Gaseosa');
    ProductBusiness::create([
        'busines_id' => $otro['negocio'], 'products_id' => $id,
        'price' => 3500, 'amount' => 5, 'qualification' => 0,
    ]);
    Sanctum::actingAs($r['user']);

    $this->postJson("/v1/negocio/productos/{$id}/variantes", ['variante' => 'Grande', 'price' => 5000], ['X-Negocio' => $r['negocio']])
        ->assertStatus(422);
    expect(Product::find($id)->group_id)->toBeNull();
});

test('el grupo propio se renombra, y al sacar la penúltima se deshace', function () {
    $r = restauranteDelPanel();
    $id = platoEn($r['negocio'], 'Mojarra');
    Sanctum::actingAs($r['user']);
    $h = ['X-Negocio' => $r['negocio']];

    $nueva = $this->postJson("/v1/negocio/productos/{$id}/variantes", [
        'variante' => 'Grande', 'price' => 32000, 'variante_actual' => 'Mediana',
    ], $h)->assertCreated()->json('data.products_id');
    $grupo = Product::find($id)->group_id;

    $this->putJson("/v1/negocio/productos/{$id}", ['grupo' => 'Mojarra frita'], $h)->assertOk();
    expect(DB::table('product_groups')->where('group_id', $grupo)->value('name'))->toBe('Mojarra frita');

    // Sacar la grande deja una sola: el grupo ya no agrupa nada.
    $this->putJson("/v1/negocio/productos/{$nueva}", ['sin_grupo' => true], $h)->assertOk();
    expect(Product::find($nueva)->group_id)->toBeNull()
        ->and(Product::find($id)->group_id)->toBeNull()
        ->and(Product::find($id)->variante)->toBeNull()
        ->and(DB::table('product_groups')->where('group_id', $grupo)->exists())->toBeFalse();
});

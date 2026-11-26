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

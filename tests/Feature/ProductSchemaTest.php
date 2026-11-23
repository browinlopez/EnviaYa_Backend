<?php

use App\Models\Business;
use App\Models\Product\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Asigna una categoría de producto a una categoría de negocio.
 *
 * La relación es de muchos a muchos y vive en `category_category_business`;
 * `category.business_category_id` quedó solo como columna heredada que apunta
 * a la primera. El backend valida contra la pivote, así que un escenario que
 * rellene únicamente la columna no representa ningún estado real: es lo que
 * hace `AdminApiController::sincronizarCategoriasDeNegocio` al guardar.
 */
function asignarCategoriaANegocio(int $categoryId, int $businessCategoryId): void
{
    DB::table('category_category_business')->insertOrIgnore([
        'category_id' => $categoryId,
        'business_category_id' => $businessCategoryId,
    ]);
}

/**
 * Crea el escenario mínimo: un negocio del tipo dado, una categoría de ese
 * tipo y un usuario autenticado por Sanctum.
 */
function productTestSetup(int $businessType): array
{
    /*
     * `business.type` es clave foránea a `category_business`, y acá el id ES
     * el dato: `$businessType` se compara contra `ProductTypeSchema::TYPE_*`
     * para decidir qué campos extra exige el producto. Por eso las categorías
     * se crean con su id puesto a mano y no con el que reparta el motor; ver
     * `tiposDeNegocioReales()` en Pest.php.
     */
    tiposDeNegocioReales();

    $business = Business::forceCreate([
        'name' => 'Negocio Test',
        'type' => $businessType,
        'qualification' => 0,
    ]);

    $category = Category::forceCreate([
        'name' => 'Categoría propia',
        'state' => 1,
        'business_category_id' => $businessType,
    ]);

    asignarCategoriaANegocio($category->category_id, $businessType);

    $user = User::factory()->create();

    /*
     * EL NEGOCIO TIENE QUE SER SUYO.
     *
     * `/v1/product/create` recibe `business_id` en el cuerpo y hasta ahora no
     * comprobaba que fuera de quien pregunta: con el identificador de otra
     * tienda —que es correlativo— se le metian productos a su catalogo. Estas
     * pruebas pasaban porque tampoco lo comprobaban ellas.
     */
    $ownerId = DB::table('owner')->insertGetId([
        'user_id' => $user->user_id, 'state' => 1,
    ], 'owner_id');

    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $business->busines_id, 'state' => 1,
    ]);

    Sanctum::actingAs($user);

    return [$business, $category];
}

/*
 * ANTES ESTA PRUEBA EXIGIA PRINCIPIO ACTIVO Y DOSIS, y esa regla es la razon
 * de que `pharmacy_products` llevara anios vacia: para dar de alta un panal,
 * un frasco de alcohol o unas gasas habia que inventarse un principio activo.
 *
 * Una drogueria de barrio vende medicamentos Y todo lo demas. Exigir el dato
 * del medicamento a lo que no lo es no mejora el catalogo: lo vacia.
 */
test('farmacia NO exige principio activo ni dosis: una droguería vende más que medicinas', function () {
    [$business, $category] = productTestSetup(2);

    $response = $this->postJson('/v1/product/create', [
        'name' => 'Pañales etapa 3 x 40',
        'category_id' => $category->category_id,
        'price' => 48900,
        'amount' => 10,
        'business_id' => $business->busines_id,
    ]);

    $response->assertSuccessful();
});

test('farmacia guarda lo suyo: contenido, registro INVIMA y si pide fórmula', function () {
    [$business, $category] = productTestSetup(2);

    $this->postJson('/v1/product/create', [
        'name' => 'Acetaminofén 500 mg',
        'category_id' => $category->category_id,
        'price' => 5000,
        'amount' => 10,
        'business_id' => $business->busines_id,
        'dosage' => '500 mg',
        'presentation' => 'Caja',
        'content' => '10 tabletas',
        'registro_invima' => 'INVIMA 2019M-0012345',
        'requiere_formula' => false,
    ])->assertSuccessful();

    $fila = DB::table('pharmacy_products')->latest('pharmacy_product_id')->first();

    expect($fila->content)->toBe('10 tabletas')
        ->and($fila->registro_invima)->toBe('INVIMA 2019M-0012345')
        // El contenido NO se mezcla con la concentracion: son dos cosas.
        ->and($fila->dosage)->toBe('500 mg');
});

test('lo que requiere fórmula queda marcado', function () {
    [$business, $category] = productTestSetup(2);

    $this->postJson('/v1/product/create', [
        'name' => 'Amoxicilina 500 mg',
        'category_id' => $category->category_id,
        'price' => 12000,
        'amount' => 5,
        'business_id' => $business->busines_id,
        'requiere_formula' => true,
    ])->assertSuccessful();

    expect(DB::table('pharmacy_products')->latest('pharmacy_product_id')->value('requiere_formula'))
        ->toEqual(1);
});

test('sin decir nada, NO requiere fórmula', function () {
    /* El booleano lleva defecto y no admite nulo: «no sabemos» y «no
       requiere» se pintarian igual, asi que son lo mismo. */
    [$business, $category] = productTestSetup(2);

    $this->postJson('/v1/product/create', [
        'name' => 'Gasa estéril',
        'category_id' => $category->category_id,
        'price' => 3200,
        'amount' => 20,
        'business_id' => $business->busines_id,
    ])->assertSuccessful();

    expect(DB::table('pharmacy_products')->latest('pharmacy_product_id')->value('requiere_formula'))
        ->toEqual(0);
});

test('rechaza campos de otro tipo de negocio', function () {
    [$business, $category] = productTestSetup(2);

    $response = $this->postJson('/v1/product/create', [
        'name' => 'Acetaminofén',
        'category_id' => $category->category_id,
        'price' => 5000,
        'amount' => 10,
        'business_id' => $business->busines_id,
        'active_ingredient' => 'Paracetamol',
        'dosage' => '500mg',
        'food_type' => 'Hamburguesa', // campo de restaurante
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['food_type']);
});

test('rechaza categoria de otro tipo de negocio', function () {
    [$business] = productTestSetup(2);

    $categoryDeTienda = Category::forceCreate([
        'name' => 'Abarrotes',
        'state' => 1,
        'business_category_id' => 1,
    ]);

    // Se asigna de verdad al tipo 1. Sin esto la categoría no estaría asignada
    // a ningún tipo y el rechazo llegaría por no existir en la pivote, no por
    // pertenecer a otro tipo, que es la regla que este test comprueba.
    asignarCategoriaANegocio($categoryDeTienda->category_id, 1);

    $response = $this->postJson('/v1/product/create', [
        'name' => 'Acetaminofén',
        'category_id' => $categoryDeTienda->category_id,
        'price' => 5000,
        'amount' => 10,
        'business_id' => $business->busines_id,
        'active_ingredient' => 'Paracetamol',
        'dosage' => '500mg',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);
});

test('crea producto de farmacia con sus campos', function () {
    [$business, $category] = productTestSetup(2);

    $response = $this->postJson('/v1/product/create', [
        'name' => 'Acetaminofén',
        'category_id' => $category->category_id,
        'price' => 5000,
        'amount' => 10,
        'business_id' => $business->busines_id,
        'active_ingredient' => 'Paracetamol',
        'dosage' => '500mg',
        'presentation' => 'Caja x 20 tabletas',
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('pharmacy_products', [
        'active_ingredient' => 'Paracetamol',
        'dosage' => '500mg',
    ]);
});

test('tienda sigue creando productos como antes (campos opcionales)', function () {
    [$business, $category] = productTestSetup(1);

    $response = $this->postJson('/v1/product/create', [
        'name' => 'Arroz Diana',
        'category_id' => $category->category_id,
        'price' => 3500,
        'amount' => 50,
        'business_id' => $business->busines_id,
        'brand' => 'Diana',
        'size' => '500g',
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('grocery_products', ['brand' => 'Diana']);
});

test('el schema devuelve los campos y categorias del tipo del negocio', function () {
    [$business, $category] = productTestSetup(3);

    $response = $this->getJson("/v1/product/schema?business_id={$business->busines_id}");

    $response->assertOk()
        ->assertJsonPath('business_type', 3)
        ->assertJsonPath('type_name', 'restaurant')
        ->assertJsonPath('categories.0.category_id', $category->category_id);

    $fields = collect($response->json('fields'));

    expect($fields->firstWhere('name', 'food_type')['required'])->toBeTrue()
        ->and($fields->pluck('name'))->toContain('is_vegan', 'allergens')
        ->and($fields->pluck('name'))->not->toContain('active_ingredient');
});

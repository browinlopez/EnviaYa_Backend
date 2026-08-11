<?php

use App\Models\Business;
use App\Models\Business\CategoryBusiness;
use App\Models\Product\Category;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Crea el escenario mínimo: un negocio del tipo dado, una categoría de ese
 * tipo y un usuario autenticado por Sanctum.
 */
function productTestSetup(int $businessType): array
{
    // business.type es FK a category_business; crear los tipos 1..N
    for ($i = 1; $i <= $businessType; $i++) {
        CategoryBusiness::forceCreate(['name' => "Tipo $i"]);
    }

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

    Sanctum::actingAs(User::factory()->create());

    return [$business, $category];
}

test('farmacia exige principio activo y dosis', function () {
    [$business, $category] = productTestSetup(2);

    $response = $this->postJson('/v1/product/create', [
        'name' => 'Acetaminofén',
        'category_id' => $category->category_id,
        'price' => 5000,
        'amount' => 10,
        'business_id' => $business->busines_id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['active_ingredient', 'dosage']);
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

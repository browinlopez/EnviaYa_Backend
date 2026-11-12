<?php

declare(strict_types=1);

use App\Http\Resources\Negocio\CategoriaDeNegocioResource;
use App\Models\Business\CategoryBusiness;
use App\Models\Rol;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * EL CATÁLOGO DE TIPOS DE NEGOCIO, QUE SE SIRVE SIN CUENTA.
 *
 * `CategoryBusinessController::index()` devolvía `CategoryBusiness::all()`
 * tal cual: la tabla entera como cuerpo de la respuesta, sin lista de claves.
 * Era el último `Model::all()` del backend.
 *
 * LO QUE LO HACÍA DISTINTO de los otros sitios de esta clase es que una de
 * las dos rutas que sirve ese método NO PIDE SESIÓN:
 *
 *   GET /v1/categories-business/indexFree    throttle:300,1 y nada más
 *   GET /v1/categories-business/index        auth:sanctum
 *
 * Así que una columna nueva en `categories_business` —un peso para ordenar,
 * una comisión por tipo de negocio, una nota del equipo— no se publicaba sola
 * a quien tiene sesión: se publicaba a cualquiera, sin cuenta.
 *
 * Y LA TERCERA PRUEBA DE ESTE ARCHIVO NO ARREGLA NADA: deja constancia. Las
 * dos rutas llaman al MISMO método y devuelven lo mismo, pero una pide sesión
 * y la otra no, así que la autenticación de `index` no protege nada: el mismo
 * dato está público un camino más allá. No es una fuga —este catálogo está
 * hecho para verse antes de entrar— pero una de las dos sobra. Cuál se retira
 * es una decisión de producto, porque las dos aparecen en el código de los
 * clientes. Mientras se decide, queda medido.
 */
function unosTiposDeNegocio(): void
{
    foreach ([['Tienda', 'Abarrotes del barrio'], ['Droguería', 'Medicamentos']] as [$n, $d]) {
        CategoryBusiness::create(['name' => $n, 'description' => $d, 'image' => 'x.png']);
    }
}

it('una columna nueva NO se publica, ni a quien no tiene cuenta', function () {
    $tipo = new CategoryBusiness;
    $tipo->setRawAttributes([
        'id' => 1,
        'name' => 'Tienda',
        'description' => 'Abarrotes del barrio',
        'image' => 'tienda.png',
        'comision_por_tipo' => '0.08',
    ], true);

    $publicado = (new CategoriaDeNegocioResource($tipo))->resolve();

    expect($publicado)->not->toHaveKey('comision_por_tipo');
    expect($publicado)->toHaveKey('name', 'Tienda');

    /* CONTROL: el crudo SÍ la saca, que es lo que devolvía el controlador.
       Sin esto, un Resource que devolviera un array vacío pasaría la de
       arriba: «no sale la columna» es verdad cuando no sale nada. */
    expect($tipo->toArray())->toHaveKey('comision_por_tipo');
});

it('la ruta publica sigue devolviendo la lista, con sus seis claves', function () {
    unosTiposDeNegocio();

    $r = $this->getJson('/v1/categories-business/indexFree');

    $r->assertStatus(200);

    $cuerpo = $r->json();

    expect($cuerpo)->toHaveCount(2);
    expect(array_keys($cuerpo[0]))
        ->toBe(['id', 'name', 'description', 'image', 'created_at', 'updated_at']);

    /* Que sigue siendo PÚBLICA: esta prueba no manda sesión. Si algún día
       alguien le pone `auth:sanctum`, el 200 se vuelve 401 y se nota acá, que
       es lo que hace falta porque la app la llama antes de que nadie entre. */
    expect($cuerpo[0]['name'])->toBe('Tienda');
});

it('las dos rutas devuelven lo MISMO, y por eso una de las dos sobra', function () {
    unosTiposDeNegocio();

    $publica = $this->getJson('/v1/categories-business/indexFree')->json();

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'rol1', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $conSesion = $this->getJson('/v1/categories-business/index')->json();

    /* Mismo método, mismo cuerpo. Dejarlo fijado sirve para dos cosas: que
       nadie «arregle» una sola de las dos y las deje diciendo cosas
       distintas, y que cuando se decida retirar una esté medido que no se
       pierde nada. */
    expect($conSesion)->toEqual($publica);
});

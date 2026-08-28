<?php

/**
 * DOS CATÁLOGOS DETRÁS DE UNA SOLA RUTA.
 *
 * `/admin/categories` sirve las categorías de producto y las de negocio según
 * el `scope`. En el catálogo de módulos son dos claves distintas —`categorias`
 * y `categorias-negocio`—, cada una con su casilla en la matriz de Áreas y su
 * entrada en el menú.
 *
 * El servidor exigía `categorias` para las cuatro rutas, así que
 * `categorias-negocio` era un módulo que aparecía en la matriz y **no protegía
 * nada**. Con eso:
 *
 *  · quien tuviera `categorias` y no `categorias-negocio` no veía la sección en
 *    el menú y podía borrar categorías de negocio llamando la ruta a mano;
 *  · quien tuviera `categorias-negocio` y no `categorias` veía la entrada del
 *    menú y recibía un 403 al abrirla — que en este proyecto se trata como un
 *    fallo de diseño, no como un acierto de la seguridad.
 *
 * Las áreas sembradas llevan las dos claves juntas, así que no se notaba. Se
 * habría notado el día que alguien las separara en Control → Áreas, que es
 * exactamente para lo que existe esa pantalla.
 */

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Un área a medida, con exactamente los módulos que se le pasen. */
function conModulos(array $modulos, string $nivel = Area::NIVEL_GESTOR): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $area = Area::create([
        'code' => 'prueba_' . substr(md5(implode(',', $modulos) . $nivel), 0, 8),
        'name' => 'Área de prueba',
        'state' => 1,
    ]);

    $area->guardarPermisos(
        collect($modulos)->mapWithKeys(fn ($m) => [$m => ['view' => true, 'manage' => true]])->all(),
    );

    $u = User::factory()->create([
        'rol' => 4, 'area_id' => $area->id, 'access_level' => $nivel,
    ]);

    Sanctum::actingAs($u);

    return $u;
}

/* ===================================================================== */

test('con solo categorias de producto no se tocan las de negocio', function () {
    conModulos(['categorias']);

    $this->getJson('/v1/admin/categories?scope=product')->assertOk();

    // Antes esto respondía 200: la ruta exigía `categorias` para las dos.
    $this->getJson('/v1/admin/categories?scope=business')->assertForbidden();

    $this->postJson('/v1/admin/categories', ['scope' => 'business', 'name' => 'Ferretería'])
        ->assertForbidden();
});

test('con solo categorias de negocio no se tocan las de producto', function () {
    conModulos(['categorias-negocio']);

    $this->getJson('/v1/admin/categories?scope=business')->assertOk();
    $this->getJson('/v1/admin/categories?scope=product')->assertForbidden();
});

test('con las dos, las dos', function () {
    conModulos(['categorias', 'categorias-negocio']);

    $this->getJson('/v1/admin/categories?scope=product')->assertOk();
    $this->getJson('/v1/admin/categories?scope=business')->assertOk();
});

test('sin scope se entiende que son las de producto', function () {
    conModulos(['categorias']);

    // El panel manda siempre el scope; quien llama a mano, no siempre. El valor
    // por defecto es el catálogo más restrictivo de los dos que ya tiene.
    $this->getJson('/v1/admin/categories')->assertOk();
});

test('un scope inventado se rechaza en vez de caer al de producto', function () {
    conModulos(['categorias', 'categorias-negocio']);

    // Caer al de producto en silencio devolvería datos que no son los pedidos.
    $this->getJson('/v1/admin/categories?scope=inventado')->assertStatus(422);
});

test('quien solo consulta no crea ni borra en ninguno de los dos', function () {
    conModulos(['categorias', 'categorias-negocio'], Area::NIVEL_CONSULTA);

    $this->getJson('/v1/admin/categories?scope=business')->assertOk();

    $r = $this->postJson('/v1/admin/categories', ['scope' => 'business', 'name' => 'Panadería'])
        ->assertForbidden();

    // El mensaje distingue "no es tuyo" de "puedes mirar pero no tocar": son
    // dos conversaciones distintas con Tecnología.
    expect($r->json('message'))->toContain('consultar');
});

test('Tecnologia alcanza los dos catalogos, como todo', function () {
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create([
        'rol' => 4,
        'area_id' => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => Area::NIVEL_GESTOR,
    ]));

    $this->getJson('/v1/admin/categories?scope=product')->assertOk();
    $this->getJson('/v1/admin/categories?scope=business')->assertOk();
});

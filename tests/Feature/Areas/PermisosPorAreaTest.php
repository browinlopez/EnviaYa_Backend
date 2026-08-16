<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use App\Support\PanelModules;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * La puerta por área.
 *
 * Antes el panel era todo-o-nada: cualquiera del equipo con rol 4 veía pagos,
 * usuarios y auditoría aunque solo viniera a cargar banners. Lo que se prueba
 * acá es que ese reparto ahora se respeta EN EL SERVIDOR, porque el menú
 * recortado del panel es comodidad de la interfaz y cualquiera puede llamar la
 * ruta a mano con su token.
 */

function comoArea(string $codigo): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $area = Area::where('code', $codigo)->firstOrFail();
    // Las pruebas de reparto usan gestores: lo que se comprueba acá es el
    // ALCANCE del área, y el nivel se prueba aparte en NivelAccesoTest.
    $user = User::factory()->create([
        'rol' => 4, 'area_id' => $area->id, 'access_level' => 'gestor',
    ]);

    Sanctum::actingAs($user);

    return $user;
}

/* --------------------------- LO BÁSICO ------------------------------- */

test('las siete áreas quedan instaladas por la migración', function () {
    // Sin seeder: la migración tiene que dejar el panel utilizable por sí sola,
    // porque en un despliegue lo único que corre seguro es `migrate`.
    $codigos = Area::pluck('code')->all();

    expect($codigos)->toContain(
        'sistema', 'gerencia', 'contabilidad', 'marketing',
        'comercial', 'sst', 'calidad',
    );
});

test('Tecnología alcanza todos los módulos del catálogo', function () {
    $area = Area::where('code', 'sistema')->first();
    $permisos = $area->permisos();

    expect($permisos)->toHaveCount(count(PanelModules::claves()));
    expect(collect($permisos)->every(fn ($p) => $p['view'] && $p['manage']))->toBeTrue();
});

test('quien no tiene área no entra a ninguna sección', function () {
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 4, 'area_id' => null]));

    $this->getJson('/v1/admin/overview')
        ->assertForbidden()
        ->assertJsonPath('message', 'Tu cuenta no tiene un área asignada. Pídele a Tecnología que te asigne una.');
});

/* ----------------------- EL REPARTO EN ACCIÓN ------------------------ */

test('Marketing gestiona su pauta pero no toca los pagos', function () {
    comoArea('marketing');

    // Lo suyo: entra.
    $this->getJson('/v1/admin/marketing/advertisers')->assertOk();
    $this->getJson('/v1/admin/marketing/banners')->assertOk();

    // Los pagos no son de su área: ni siquiera los ve.
    $this->getJson('/v1/admin/payments')->assertForbidden();
});

test('Marketing ve el catálogo para segmentar, pero no lo edita', function () {
    comoArea('marketing');

    // Necesita saber qué negocios existen para armar la segmentación…
    $this->getJson('/v1/admin/businesses')->assertOk();

    // …pero mantener el catálogo es de Comercial.
    $this->postJson('/v1/admin/businesses', ['name' => 'Intento'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Tu área (Marketing) puede consultar esta sección, pero no modificarla.');
});

test('Contabilidad gestiona pagos y solo consulta los pedidos', function () {
    comoArea('contabilidad');

    $this->getJson('/v1/admin/payments')->assertOk();
    $this->getJson('/v1/admin/orders')->assertOk();

    // Cambiar el catálogo no le corresponde.
    $this->postJson('/v1/admin/products', ['name' => 'X'])->assertForbidden();
});

test('SST llega a los domiciliarios y a nada más', function () {
    comoArea('sst');

    $this->getJson('/v1/admin/domiciliaries')->assertOk();

    // Su alcance es deliberadamente estrecho: cuatro módulos.
    $this->getJson('/v1/admin/payments')->assertForbidden();
    $this->getJson('/v1/admin/businesses')->assertForbidden();
    $this->getJson('/v1/admin/marketing/banners')->assertForbidden();
});

test('Calidad puede retirar una reseña pero no editar negocios', function () {
    comoArea('calidad');

    $this->getJson('/v1/admin/reviews')->assertOk();
    $this->getJson('/v1/admin/chats')->assertOk();

    $this->getJson('/v1/admin/businesses')->assertOk();          // observa
    $this->postJson('/v1/admin/businesses', ['name' => 'X'])->assertForbidden(); // no interviene
});

test('Comercial mantiene el catálogo pero no reparte permisos', function () {
    comoArea('comercial');

    $this->getJson('/v1/admin/businesses')->assertOk();
    $this->getJson('/v1/admin/products')->assertOk();

    // El gestor de accesos es solo de Tecnología.
    $this->getJson('/v1/admin/areas')->assertForbidden();
});

test('Gerencia lo ve todo y no cambia nada', function () {
    comoArea('gerencia');

    $this->getJson('/v1/admin/payments')->assertOk();
    $this->getJson('/v1/admin/orders')->assertOk();
    $this->getJson('/v1/admin/marketing/banners')->assertOk();

    // Ni una sola escritura: su cuenta no debe poder convertirse en una
    // segunda cuenta de administrador sin que nadie lo haya decidido.
    $this->postJson('/v1/admin/businesses', ['name' => 'X'])->assertForbidden();
    $this->postJson('/v1/admin/marketing/coupons', ['code' => 'X'])->assertForbidden();

    // Y tampoco el gestor de accesos: se le excluyó a propósito, porque exige
    // `gestionar` hasta para listar y le habría quedado un menú con una
    // sección que siempre responde 403.
    $this->getJson('/v1/admin/areas')->assertForbidden();
});

/* ------------------------ EL GESTOR DE ÁREAS ------------------------- */

test('solo Tecnología entra al gestor de áreas', function () {
    comoArea('sistema');
    $this->getJson('/v1/admin/areas')->assertOk();

    comoArea('marketing');
    $this->getJson('/v1/admin/areas')->assertForbidden();
});

test('el área de sistema no se puede modificar ni eliminar', function () {
    comoArea('sistema');
    $sistema = Area::where('code', 'sistema')->first();

    // Es la única con acceso total y la que reparte los permisos: si se
    // pudiera recortar o borrar, un descuido dejaría la instalación sin nadie
    // capaz de arreglarla.
    $this->putJson("/v1/admin/areas/{$sistema->id}", ['name' => 'Otro'])
        ->assertStatus(422);

    $this->deleteJson("/v1/admin/areas/{$sistema->id}")->assertStatus(422);
});

test('no se puede quitar el gestor a la propia área', function () {
    comoArea('sistema');

    // Se crea un área con el gestor, se asigna a sí mismo y se intenta quitar.
    $id = $this->postJson('/v1/admin/areas', [
        'name'        => 'Tecnología B',
        'permissions' => ['areas' => ['view' => true, 'manage' => true]],
    ])->assertCreated()->json('id');

    // Se vuelve a autenticar tras el cambio: `actingAs` conserva la MISMA
    // instancia de usuario entre peticiones, así que actualizar solo la tabla
    // dejaría al guardián comparando contra el área anterior. En producción no
    // pasa, porque el usuario se carga de nuevo en cada petición.
    $yo = auth()->user();
    DB::table('user')->where('user_id', $yo->user_id)->update(['area_id' => $id]);
    Sanctum::actingAs(User::find($yo->user_id));

    $this->putJson("/v1/admin/areas/{$id}", [
        'permissions' => ['panel' => ['view' => true]],
    ])->assertStatus(422)
        ->assertJsonPath('message', 'No puedes quitarle el acceso a "Roles y accesos" a tu propia área: nadie podría volver a repartir permisos desde el panel.');
});

test('no se elimina un área que tiene personas', function () {
    comoArea('sistema');

    $marketing = Area::where('code', 'marketing')->first();
    User::factory()->create(['rol' => 4, 'area_id' => $marketing->id]);

    $this->deleteJson("/v1/admin/areas/{$marketing->id}")
        ->assertStatus(422);
});

test('gestionar implica ver al guardar la matriz', function () {
    comoArea('sistema');

    $id = $this->postJson('/v1/admin/areas', [
        'name'        => 'Prueba',
        // Se pide gestionar sin ver: no existe esa combinación.
        'permissions' => ['pagos' => ['view' => false, 'manage' => true]],
    ])->assertCreated()->json('id');

    $permisos = Area::find($id)->permisos();

    expect($permisos['pagos']['view'])->toBeTrue()
        ->and($permisos['pagos']['manage'])->toBeTrue();
});

test('mis permisos devuelve el área y el catálogo para dibujar el menú', function () {
    comoArea('sst');

    $r = $this->getJson('/v1/admin/me/permissions')->assertOk();

    expect($r->json('area.code'))->toBe('sst')
        ->and($r->json('permissions'))->toHaveKey('domiciliarios')
        ->and($r->json('permissions'))->not->toHaveKey('pagos')
        // El catálogo completo viaja siempre: el panel lo necesita para dibujar
        // la matriz aunque el área no tenga esos módulos.
        ->and($r->json('catalog'))->toHaveCount(count(PanelModules::claves()));
});

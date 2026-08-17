<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * TODA ENTIDAD QUE ADMITE ARCHIVOS SABE DECIR SU NOMBRE
 *
 * `MediaService::RAICES` declara qué entidades guardan archivos y `nombreDe()`
 * resuelve el rótulo con el que se arma su carpeta. Son dos listas que tienen
 * que cubrir lo mismo.
 *
 * Estuvieron separadas y derivaron: se dio de alta `banners` en las raíces y no
 * en la resolución de nombres, y la ficha de un banner respondía "El registro no
 * existe" — un 404 que hablaba de una lista incompleta, no del banner. Esta
 * prueba recorre las raíces una por una para que el próximo alta no pueda
 * repetirlo.
 */

/** Alguien de un área concreta, con el nivel que se pida. */
function personaDeMedios(string $codigoArea, string $nivel = Area::NIVEL_GESTOR): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', $codigoArea)->firstOrFail()->id,
        'access_level' => $nivel,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

/** Personal de Tecnología, que es la única área con acceso a todo. */
function adminDeMedios(): User
{
    // `user.rol` tiene clave foránea contra la tabla `rol`, que en la base de
    // pruebas arranca vacía.
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => Area::NIVEL_GESTOR,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

it('resuelve el nombre de cada entidad declarada en RAICES', function () {
    $medios = app(MediaService::class);

    foreach (array_keys(MediaService::RAICES) as $entidad) {
        // Con un id que no existe tiene que devolver null, NO reventar. Un
        // `UnhandledMatchError` acá significa que la raíz se dio de alta sin su
        // resolución de nombre, que es exactamente lo que pasó con `banners`.
        expect($medios->nombreDe($entidad, 999_999_999))
            ->toBeNull("la raíz \"{$entidad}\" no tiene resolución de nombre");
    }
});

it('rechaza una entidad que no maneja archivos', function () {
    expect(fn () => app(MediaService::class)->nombreDe('pedidos', 1))
        ->toThrow(RuntimeException::class);
});

it('el banner devuelve su título como nombre de carpeta', function () {
    $anunciante = DB::table('advertisers')->insertGetId([
        'name'       => 'Anunciante de prueba',
        'state'      => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $campana = DB::table('ad_campaigns')->insertGetId([
        'advertiser_id' => $anunciante,
        'name'          => 'Campaña de prueba',
        'objective'     => 'traffic',
        'starts_at'     => now()->toDateString(),
        'ends_at'       => now()->addDays(30)->toDateString(),
        'state'         => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $banner = DB::table('banners')->insertGetId([
        'campaign_id' => $campana,
        'title'       => 'Refresca tu verano',
        'placement'   => 'home_hero',
        'platform'    => 'both',
        'link_type'   => 'none',
        'state'       => 1,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    expect(app(MediaService::class)->nombreDe('banners', $banner))
        ->toBe('Refresca tu verano');
});

it('la ficha del banner ya no responde que el registro no existe', function () {
    $anunciante = DB::table('advertisers')->insertGetId([
        'name' => 'Otro anunciante', 'state' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $campana = DB::table('ad_campaigns')->insertGetId([
        'advertiser_id' => $anunciante, 'name' => 'Otra campaña',
        'objective' => 'traffic', 'starts_at' => now()->toDateString(),
        'ends_at' => now()->addDays(30)->toDateString(), 'state' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $banner = DB::table('banners')->insertGetId([
        'campaign_id' => $campana, 'title' => 'Pieza con imagen',
        'placement' => 'home_hero', 'platform' => 'both',
        'link_type' => 'none', 'state' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    adminDeMedios();

    $this->getJson("/v1/admin/media/banners/{$banner}")
        ->assertOk()
        ->assertJsonPath('multiple', false) // un banner lleva UNA imagen
        ->assertJsonStructure(['folder', 'configured', 'files']);
});

it('distingue el tipo no admitido del registro inexistente', function () {
    adminDeMedios();

    // Entidad que sencillamente no guarda archivos.
    $this->getJson('/v1/admin/media/pedidos/1')
        ->assertNotFound()
        ->assertJsonPath('message', 'Los registros de tipo "pedidos" no manejan archivos.');

    // Entidad válida, registro que no está: otro mensaje, otro problema.
    $this->getJson('/v1/admin/media/banners/999999')
        ->assertNotFound()
        ->assertJsonPath('message', 'El registro no existe.');
});

/* ==================================================================
   LOS ARCHIVOS TAMBIÉN PASAN POR EL REPARTO DE ÁREAS
   ================================================================== */

it('un área ajena no puede ni ver los archivos de un negocio', function () {
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda ajena', 'state' => 1, 'qualification' => 0,
    ], 'busines_id');

    // SST ve domiciliarios e incidentes; los negocios no son suyos.
    personaDeMedios('sst');

    $this->getJson("/v1/admin/media/negocios/{$negocio}")
        ->assertForbidden()
        ->assertJsonPath('message', 'Los archivos de esta sección no son de tu área.');
});

it('quien solo consulta ve los archivos pero no los borra', function () {
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda de Comercial', 'state' => 1, 'qualification' => 0,
    ], 'busines_id');

    // Comercial administra los negocios; en nivel consulta, solo mira.
    personaDeMedios('comercial', Area::NIVEL_CONSULTA);

    $this->getJson("/v1/admin/media/negocios/{$negocio}")->assertOk();

    /*
     * Este es el agujero que había: las rutas de medios eran las únicas de
     * /admin sin puerta por módulo, así que bastaba con ser del equipo para
     * borrar el logo de cualquier negocio. Que la pantalla no ofreciera el
     * botón no es una defensa: la ruta se llama a mano.
     */
    $this->deleteJson("/v1/admin/media/negocios/{$negocio}", ['id' => 1])
        ->assertForbidden()
        ->assertJsonPath('message', 'Puedes consultar estos archivos, no modificarlos.');

    $this->postJson("/v1/admin/media/negocios/{$negocio}")->assertForbidden();
});

it('el gestor de su área sí puede tocarlos', function () {
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda propia', 'state' => 1, 'qualification' => 0,
    ], 'busines_id');

    personaDeMedios('comercial');

    // Pasa la puerta: se queda en la validación del archivo, no en el permiso.
    $this->postJson("/v1/admin/media/negocios/{$negocio}")
        ->assertStatus(422);
});

it('cada entidad responde a SU módulo, no a uno solo', function () {
    // Marketing manda sobre los banners y no sobre los negocios: si la puerta
    // usara una única clave fija, una de las dos quedaría mal.
    $anunciante = DB::table('advertisers')->insertGetId([
        'name' => 'Marca', 'state' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $campana = DB::table('ad_campaigns')->insertGetId([
        'advertiser_id' => $anunciante, 'name' => 'Camp', 'objective' => 'traffic',
        'starts_at' => now()->toDateString(), 'ends_at' => now()->addDays(5)->toDateString(),
        'state' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $banner = DB::table('banners')->insertGetId([
        'campaign_id' => $campana, 'title' => 'Pieza', 'placement' => 'home_hero',
        'platform' => 'both', 'link_type' => 'none', 'state' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $negocio = DB::table('business')->insertGetId([
        'name' => 'Negocio', 'state' => 1, 'qualification' => 0,
    ], 'busines_id');

    personaDeMedios('marketing');

    // Marketing GESTIONA sus piezas...
    $this->getJson("/v1/admin/media/banners/{$banner}")->assertOk();
    $this->postJson("/v1/admin/media/banners/{$banner}")->assertStatus(422);

    /*
     * ...y solo CONSULTA los negocios: los ve porque los necesita para elegir
     * a quién destacar, pero el logo de una tienda lo cambia Comercial. Con una
     * clave de módulo única para todos los archivos, este caso quedaría mal en
     * alguno de los dos sentidos.
     */
    $this->getJson("/v1/admin/media/negocios/{$negocio}")->assertOk();
    $this->postJson("/v1/admin/media/negocios/{$negocio}")
        ->assertForbidden()
        ->assertJsonPath('message', 'Puedes consultar estos archivos, no modificarlos.');
});

it('toda entidad con archivos declara qué módulo la manda', function () {
    $medios = app(MediaService::class);

    // Misma lección que con la resolución de nombres: dos listas que tienen
    // que cubrir lo mismo, separadas, derivan. Una raíz sin módulo dejaría sus
    // archivos otra vez sin puerta.
    foreach (array_keys(MediaService::RAICES) as $entidad) {
        expect($medios->moduloDe($entidad))
            ->not->toBeNull("la raíz \"{$entidad}\" no declara módulo");
    }
});

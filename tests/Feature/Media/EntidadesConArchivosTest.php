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

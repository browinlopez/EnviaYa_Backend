<?php

use App\Models\User;
use App\Support\PanelModules;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LOS FALLOS DE LA APP, VISIBLES EN EL PANEL.
 *
 * La tabla `client_errors` existía y solo se podía leer entrando al contenedor,
 * así que en la práctica no los leía nadie. Guardar un fallo sin sitio donde
 * mirarlo es casi tan inútil como no guardarlo.
 *
 * Lo que se fija acá es lo que hace útil la pantalla: que agrupe. El mismo
 * fallo en cien teléfonos es UN problema, no cien, y si el resumen no agrupara
 * habría que contarlos a ojo.
 */

/** Un administrador con el módulo concedido. */
function adminConFallos(): User
{
    \App\Models\Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 4]);

    /*
     * El area concede los modulos por la tabla `area_module`, no por una
     * columna: es la matriz que edita «Roles y accesos», con ver y gestionar
     * por separado.
     */
    $area = DB::table('areas')->insertGetId([
        // `code` es obligatorio: es como el panel identifica el area.
        'code'      => 'tec-' . uniqid(),
        'name'      => 'Tecnología ' . uniqid(),
        'state'     => 1,
        'is_system' => 0,
    ]);

    DB::table('area_module')->insert([
        'area_id'    => $area,
        'module'     => 'fallos',
        'can_view'   => 1,
        'can_manage' => 0,
    ]);

    DB::table('user')->where('user_id', $user->user_id)->update(['area_id' => $area]);

    return $user->fresh();
}

function fallo(array $extra = []): void
{
    DB::table('client_errors')->insert(array_merge([
        'platform'    => 'android',
        'app_version' => '1.0.8',
        'pantalla'    => 'CartScreen',
        'mensaje'     => 'Cannot read property x of undefined',
        'traza'       => 'en <CartScreen>',
        'created_at'  => now(),
    ], $extra));
}

it('el modulo existe en el catalogo del panel', function () {
    // Sin esto, conceder el acceso desde «Roles y accesos» sería imposible.
    expect(PanelModules::existe('fallos'))->toBeTrue()
        ->and(PanelModules::rotulo('fallos'))->toBe('Fallos de la app');
});

it('el resumen agrupa: el mismo fallo en cinco telefonos es UNO', function () {
    for ($i = 0; $i < 5; $i++) {
        fallo(['user_id' => 100 + $i]);
    }

    Sanctum::actingAs(adminConFallos());

    $r = $this->getJson('/v1/admin/fallos/resumen?days=7')->assertOk()->json();

    expect($r['total'])->toBe(5)
        // Cinco fallos, UN problema.
        ->and($r['grupos'])->toHaveCount(1)
        ->and((int) $r['grupos'][0]['veces'])->toBe(5)
        // Y la cifra que decide la prioridad: a cuánta gente le pasó.
        ->and((int) $r['grupos'][0]['personas'])->toBe(5);
});

it('distingue cinco veces a UNA persona de cinco personas distintas', function () {
    /*
     * Es la diferencia que decide qué se arregla primero: alguien que insiste
     * cinco veces es un caso; cinco personas distintas es una avería.
     */
    for ($i = 0; $i < 5; $i++) {
        fallo(['user_id' => 77, 'mensaje' => 'El mismo, una sola persona']);
    }

    Sanctum::actingAs(adminConFallos());

    $grupos = collect($this->getJson('/v1/admin/fallos/resumen?days=7')->json('grupos'));
    $grupo = $grupos->firstWhere('mensaje', 'El mismo, una sola persona');

    expect((int) $grupo['veces'])->toBe(5)
        ->and((int) $grupo['personas'])->toBe(1);
});

it('agrupa por version, para saber si lo trajo la ultima entrega', function () {
    fallo(['app_version' => '1.0.8']);
    fallo(['app_version' => '1.0.9']);
    fallo(['app_version' => '1.0.9']);

    Sanctum::actingAs(adminConFallos());

    $porVersion = collect($this->getJson('/v1/admin/fallos/resumen?days=7')->json('por_version'));

    // La que más falla, primero: es la primera pregunta después de publicar.
    expect($porVersion->first()['app_version'])->toBe('1.0.9')
        ->and((int) $porVersion->first()['veces'])->toBe(2);
});

it('el listado uno por uno tambien responde', function () {
    fallo();

    Sanctum::actingAs(adminConFallos());

    $this->getJson('/v1/admin/fallos')->assertOk();
});

it('sin el modulo concedido no se ven', function () {
    \App\Models\Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    // Un administrador SIN el módulo: el panel se acota por área, no por rol.
    Sanctum::actingAs(User::factory()->create(['rol' => 4]));

    $this->getJson('/v1/admin/fallos/resumen')->assertForbidden();
});

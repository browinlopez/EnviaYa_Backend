<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Nivel de acceso dentro del área.
 *
 * El área dice QUÉ SECCIONES le tocan a alguien; el nivel dice QUÉ PUEDE HACER
 * en ellas. Un auxiliar de contabilidad y el contador miran las mismas
 * pantallas, pero solo uno aprueba liquidaciones.
 */

function comoNivel(string $codigoArea, string $nivel): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
    $area = Area::where('code', $codigoArea)->firstOrFail();

    $u = User::factory()->create([
        'rol'          => 4,
        'area_id'      => $area->id,
        'access_level' => $nivel,
    ]);

    Sanctum::actingAs($u);

    return $u;
}

/* ------------------------- EL ALCANCE NO CAMBIA ------------------------ */

test('el auxiliar ve exactamente lo mismo que su jefe', function () {
    $area = Area::where('code', 'contabilidad')->first();

    $delGestor   = $area->permisos(Area::NIVEL_GESTOR);
    $deConsulta  = $area->permisos(Area::NIVEL_CONSULTA);

    // Mismas secciones: el nivel recorta la profundidad, no el alcance.
    // Quitarle también la vista lo dejaría sin poder hacer su trabajo.
    expect(array_keys($deConsulta))->toBe(array_keys($delGestor));

    foreach ($deConsulta as $modulo => $p) {
        expect($p['view'])->toBeTrue();
        expect($p['manage'])->toBeFalse();
    }
});

test('consulta puede leer todo lo de su área', function () {
    comoNivel('contabilidad', Area::NIVEL_CONSULTA);

    $this->getJson('/v1/admin/payments')->assertOk();
    $this->getJson('/v1/admin/settlements')->assertOk();
    $this->getJson('/v1/admin/orders')->assertOk();
});

/* ------------------------ PERO NO PUEDE MODIFICAR ---------------------- */

test('consulta no puede crear en lo que su área sí gestiona', function () {
    comoNivel('contabilidad', Area::NIVEL_CONSULTA);

    $r = $this->postJson('/v1/admin/settlements', [
        'type' => 'business', 'target_id' => 1,
        'period_start' => now()->subDays(10)->toDateString(),
        'period_end'   => now()->toDateString(),
    ]);

    $r->assertForbidden();

    // El mensaje distingue "tu área no lo alcanza" de "tu nivel no te deja":
    // llevan a dos conversaciones distintas con Tecnología.
    expect($r->json('message'))->toContain('solo consulta');
});

test('el mismo endpoint sí lo acepta a un gestor', function () {
    comoNivel('contabilidad', Area::NIVEL_GESTOR);

    // 422 y no 403: llega al controlador y falla por datos, no por permisos.
    $this->postJson('/v1/admin/settlements', [
        'type' => 'business', 'target_id' => 999999,
        'period_start' => now()->subDays(10)->toDateString(),
        'period_end'   => now()->toDateString(),
    ])->assertStatus(422);
});

test('consulta en SST no registra documentos ni incidentes', function () {
    comoNivel('sst', Area::NIVEL_CONSULTA);

    $this->getJson('/v1/admin/sst/documentos')->assertOk();
    $this->getJson('/v1/admin/sst/incidentes')->assertOk();

    $this->postJson('/v1/admin/sst/documentos', [
        'domiciliary_id' => 1, 'type' => 'soat',
    ])->assertForbidden();

    $this->postJson('/v1/admin/sst/incidentes', [
        'occurred_at' => now()->toDateTimeString(),
        'type' => 'caida', 'description' => 'x',
    ])->assertForbidden();
});

test('consulta en Marketing no toca la pauta', function () {
    comoNivel('marketing', Area::NIVEL_CONSULTA);

    $this->getJson('/v1/admin/marketing/banners')->assertOk();

    $this->postJson('/v1/admin/marketing/advertisers', ['name' => 'X'])
        ->assertForbidden();
});

test('un auxiliar de Tecnología tampoco reparte permisos', function () {
    // El nivel se aplica también al área de sistema: tener todo el alcance no
    // significa poder cambiarlo todo.
    comoNivel('sistema', Area::NIVEL_CONSULTA);

    $this->getJson('/v1/admin/areas')->assertForbidden();
});

/* -------------------------- ASIGNAR EL NIVEL --------------------------- */

test('Tecnología puede cambiarle el nivel a alguien', function () {
    comoNivel('sistema', Area::NIVEL_GESTOR);

    $marketing = Area::where('code', 'marketing')->first();
    $otro = User::factory()->create([
        'rol' => 4, 'area_id' => $marketing->id, 'access_level' => Area::NIVEL_CONSULTA,
    ]);

    $this->putJson("/v1/admin/areas-members/{$otro->user_id}", [
        'access_level' => Area::NIVEL_GESTOR,
    ])->assertOk();

    expect(DB::table('user')->where('user_id', $otro->user_id)->value('access_level'))
        ->toBe('gestor');
});

test('nadie puede ponerse a sí mismo en solo consulta', function () {
    $yo = comoNivel('sistema', Area::NIVEL_GESTOR);

    // Pasar a consulta apaga la gestión de TODO, incluido este gestor: la
    // persona quedaría sin poder devolverse el nivel.
    $r = $this->putJson("/v1/admin/areas-members/{$yo->user_id}", [
        'access_level' => Area::NIVEL_CONSULTA,
    ]);

    $r->assertStatus(422);
    expect($r->json('message'))->toContain('a ti mismo');
});

test('mis permisos declara el nivel y llega ya recortado', function () {
    comoNivel('marketing', Area::NIVEL_CONSULTA);

    $r = $this->getJson('/v1/admin/me/permissions')->assertOk();

    // Se lee el arreglo completo: las claves llevan punto ("marketing.banners")
    // y `json()` lo interpretaría como una ruta anidada que no existe.
    $permisos = $r->json('permissions');

    expect($r->json('access_level'))->toBe('consulta')
        // El panel no necesita saber que existen los niveles: recibe menos
        // permisos y oculta menos botones, sin una segunda regla que pueda
        // discrepar del servidor.
        ->and($permisos['marketing.banners']['view'])->toBeTrue()
        ->and($permisos['marketing.banners']['manage'])->toBeFalse();
});

test('quien ya trabajaba en el panel conserva su nivel de gestor', function () {
    // El defecto seguro es `consulta`, pero aplicarlo a quien ya estaba habría
    // dejado al equipo entero en solo lectura de un día para otro.
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
    $sistema = Area::where('code', 'sistema')->first();

    $id = DB::table('user')->insertGetId([
        'name' => 'Antiguo', 'email' => 'antiguo@ejemplo.com',
        'password' => bcrypt('x'), 'rol' => 4, 'area_id' => $sistema->id,
        'qualification' => 0, 'state' => 1,
    ], 'user_id');

    // Sin declarar nivel, la columna toma el defecto prudente.
    expect(DB::table('user')->where('user_id', $id)->value('access_level'))
        ->toBe('consulta');
});

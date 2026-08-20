<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * EL LIBRO DE EXCEL DE LOS REPORTES
 *
 * Los generadores multi-hoja existían desde antes y colgaban del panel en Blade,
 * detrás de una sesión web y SIN autorización por área: cualquier usuario con
 * cuenta podía descargar el reporte financiero. Acá se comprueba lo que cambió
 * al traerlos al API: que sigan produciendo un libro y que ahora pidan permiso.
 */

function comoAreaLibro(string $codigo, string $nivel = Area::NIVEL_GESTOR): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', $codigo)->firstOrFail()->id,
        'access_level' => $nivel,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

it('entrega un libro de Excel de verdad', function () {
    comoAreaLibro('contabilidad');

    $r = $this->get('/v1/admin/reports/financial/excel?range=30')->assertOk();

    $nombre = $r->headers->get('content-disposition');

    // Un .xlsx es un ZIP: empieza por "PK". Comprobar solo el 200 dejaría pasar
    // una respuesta vacía o una página de error con la cabecera correcta.
    expect(substr($r->streamedContent(), 0, 2))->toBe('PK');
    expect($nombre)->toContain('reporte-financiero');
    expect($nombre)->toContain('.xlsx');
});

it('el periodo del libro es el que se pide, no otro', function () {
    comoAreaLibro('contabilidad');

    // El nombre del archivo lleva las fechas: es la única forma de saber, con el
    // libro ya descargado, de qué periodo es. Si la pantalla dice un rango y el
    // libro trae otro, el número que alguien copie a un correo estará mal.
    $r = $this->get('/v1/admin/reports/financial/excel?from=2026-03-01&to=2026-03-31')
        ->assertOk();

    expect($r->headers->get('content-disposition'))
        ->toContain('2026-03-01-a-2026-03-31');
});

it('endereza las fechas si vienen al revés', function () {
    comoAreaLibro('contabilidad');

    $r = $this->get('/v1/admin/reports/commercial/excel?from=2026-03-31&to=2026-03-01')
        ->assertOk();

    expect($r->headers->get('content-disposition'))
        ->toContain('2026-03-01-a-2026-03-31');
});

it('el reporte por negocio no tiene libro y lo dice', function () {
    comoAreaLibro('contabilidad');

    // Su tabla ya se exporta a CSV: dos caminos al mismo archivo solo obligan a
    // elegir sin ninguna razón.
    $this->getJson('/v1/admin/reports/businesses/excel')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Ese reporte no se entrega en Excel.');
});

it('un área sin reportes no puede descargarlo', function () {
    /*
     * Esto es lo que NO pasaba antes.
     *
     * En el panel de Blade la ruta estaba detrás de `auth` y nada más, así que
     * bastaba con tener cuenta —un comprador incluido— para bajarse el reporte
     * financiero de la plataforma.
     */
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $this->getJson('/v1/admin/reports/financial/excel')->assertForbidden();
});

it('quien solo consulta SÍ puede descargarlo', function () {
    // Consultar no es estar inutilizado: bajarse el reporte es leer.
    comoAreaLibro('gerencia', Area::NIVEL_CONSULTA);

    $this->get('/v1/admin/reports/operational/excel?range=7')->assertOk();
});

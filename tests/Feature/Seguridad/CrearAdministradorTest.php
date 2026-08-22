<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;

/* La fila 4 de `rol` es clave foránea del usuario. En la base en memoria de
   las pruebas no la pone nadie; en el servidor la siembra
   RolesAndPermissionsSeeder. */
beforeEach(function () {
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
});

/**
 * El comando que da acceso al panel.
 *
 * Lo que se fija acá es sobre todo que la cuenta salga COMPLETA. El fallo que
 * este comando evita no es "no se pudo crear": es crear un rol 4 sin área, que
 * entra al panel y lo ve vacío porque `me/permissions` devuelve `[]`. Eso
 * parece un fallo del panel y es una cuenta a medio hacer.
 */

test('la cuenta de Tecnología sale con todo lo que el panel necesita', function () {
    $this->artisan('admin:crear', [
        '--email'  => 'sistemas@ejemplo.test',
        '--nombre' => 'Sistemas',
        '--clave'  => 'ClaveDePrueba1*',
    ])->assertSuccessful();

    $usuario = User::where('email', 'sistemas@ejemplo.test')->first();
    $area    = Area::where('code', 'sistema')->first();

    expect($usuario)->not->toBeNull()
        ->and((int) $usuario->rol)->toBe(4)
        ->and((int) $usuario->area_id)->toBe((int) $area->id)
        ->and($usuario->access_level)->toBe(Area::NIVEL_GESTOR)
        // Sin esto el acceso queda a medias.
        ->and($usuario->email_verified_at)->not->toBeNull();
});

test('Tecnología puede ver y gestionar todos los módulos', function () {
    $this->artisan('admin:crear', [
        '--email'  => 'sistemas@ejemplo.test',
        '--nombre' => 'Sistemas',
        '--clave'  => 'ClaveDePrueba1*',
    ])->assertSuccessful();

    $area     = Area::where('code', 'sistema')->first();
    $permisos = $area->permisos(Area::NIVEL_GESTOR);

    expect($permisos)->not->toBeEmpty();

    foreach ($permisos as $modulo => $puede) {
        expect($puede['view'] ?? false)->toBeTrue("debería ver {$modulo}")
            ->and($puede['manage'] ?? false)->toBeTrue("debería gestionar {$modulo}");
    }
});

test('la clave sirve para autenticarse de verdad', function () {
    $this->artisan('admin:crear', [
        '--email'  => 'sistemas@ejemplo.test',
        '--nombre' => 'Sistemas',
        '--clave'  => 'ClaveDePrueba1*',
    ])->assertSuccessful();

    $this->postJson('/v1/login', [
        'email'    => 'sistemas@ejemplo.test',
        'password' => 'ClaveDePrueba1*',
    ])->assertOk();
});

test('repone el acceso de una cuenta que ya existe en vez de fallar', function () {
    // El uso más frecuente no es dar de alta, es recuperar una clave perdida.
    $this->artisan('admin:crear', [
        '--email' => 'sistemas@ejemplo.test',
        '--nombre' => 'Sistemas',
        '--clave' => 'PrimeraClave1*',
    ])->assertSuccessful();

    $this->artisan('admin:crear', [
        '--email' => 'sistemas@ejemplo.test',
        '--nombre' => 'Sistemas',
        '--clave' => 'SegundaClave1*',
    ])->assertSuccessful();

    expect(User::where('email', 'sistemas@ejemplo.test')->count())->toBe(1);

    $this->postJson('/v1/login', [
        'email'    => 'sistemas@ejemplo.test',
        'password' => 'SegundaClave1*',
    ])->assertOk();
});

test('un área que no existe no crea nada a medias', function () {
    $this->artisan('admin:crear', [
        '--email'  => 'nadie@ejemplo.test',
        '--nombre' => 'Nadie',
        '--clave'  => 'ClaveDePrueba1*',
        '--area'   => 'departamento-inventado',
    ])->assertFailed();

    expect(User::where('email', 'nadie@ejemplo.test')->exists())->toBeFalse();
});

test('rechaza una clave corta antes de tocar la base', function () {
    $this->artisan('admin:crear', [
        '--email'  => 'nadie@ejemplo.test',
        '--nombre' => 'Nadie',
        '--clave'  => 'corta',
    ])->assertFailed();

    expect(User::where('email', 'nadie@ejemplo.test')->exists())->toBeFalse();
});

test('el nivel de consulta recorta los permisos', function () {
    $this->artisan('admin:crear', [
        '--email'  => 'mirón@ejemplo.test',
        '--nombre' => 'Solo mira',
        '--clave'  => 'ClaveDePrueba1*',
        '--nivel'  => Area::NIVEL_CONSULTA,
    ])->assertSuccessful();

    $usuario = User::where('email', 'mirón@ejemplo.test')->first();
    expect($usuario->access_level)->toBe(Area::NIVEL_CONSULTA);

    $permisos = Area::find($usuario->area_id)->permisos(Area::NIVEL_CONSULTA);

    foreach ($permisos as $modulo => $puede) {
        expect($puede['manage'] ?? false)->toBeFalse("no debería gestionar {$modulo}");
    }
});

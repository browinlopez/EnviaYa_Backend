<?php

use Database\Seeders\ConjuntoVillaCarolinaDemoSeeder;
use Database\Seeders\DrogueriaDemoSeeder;
use Database\Seeders\InversionistaDemoSeeder;
use Database\Seeders\MuestraDeTresNegociosSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Las semillas de demostración son datos falsos: en producción no escriben
 * nada, aunque alguien las corra a mano. Solo un permiso explícito
 * (`semillas.permitir_en_produccion`) las deja pasar.
 */
dataset('semillas de demostracion', [
    MuestraDeTresNegociosSeeder::class,
    ConjuntoVillaCarolinaDemoSeeder::class,
    DrogueriaDemoSeeder::class,
    InversionistaDemoSeeder::class,
]);

test('en produccion no escriben nada', function (string $semilla) {
    app()->detectEnvironment(fn () => 'production');
    config(['semillas.permitir_en_produccion' => false]);

    $antes = [
        'user' => DB::table('user')->count(),
        'business' => DB::table('business')->count(),
        'orderssales' => DB::table('orderssales')->count(),
        'complex_entries' => DB::table('complex_entries')->count(),
    ];

    // Directo, como lo haría alguien que lo corre a mano saltándose la
    // confirmación de `db:seed --force`: el freno tiene que estar en la semilla.
    app($semilla)->run();

    expect([
        'user' => DB::table('user')->count(),
        'business' => DB::table('business')->count(),
        'orderssales' => DB::table('orderssales')->count(),
        'complex_entries' => DB::table('complex_entries')->count(),
    ])->toBe($antes);
})->with('semillas de demostracion');

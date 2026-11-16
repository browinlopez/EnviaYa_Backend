<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LA TORRE DE CONTROL: UNA CONSULTA POR MOTO, Y SOLO UNA
 *
 * `GET /v1/negocio/torre` es la pantalla que el tendero deja abierta y que se
 * recarga cada quince segundos. Hacía 33 consultas para una flota de diez, y
 * **dos líneas distintas eran una por moto**:
 *
 *   · el saldo de efectivo —`saldo()` dentro del recorrido—, que NO era
 *     deliberado y ahora sale en una sola consulta para toda la flota;
 *   · la última posición conocida, que SÍ lo es. Su docblock lo explica: la
 *     alternativa era una consulta que traía todas las posiciones de las
 *     últimas doce horas de toda la flota —cuarenta mil filas convertidas en
 *     objetos de PHP para leer veinte— y veinte consultas que leen una fila
 *     cada una cuestan muchísimo menos. Comprobado además que el índice
 *     `(domiciliary_id, created_at)` existe y aparece en `possible_keys`.
 *
 * ESTA PRUEBA FIJA LA PENDIENTE EN EXACTAMENTE UNO, y eso es más útil que un
 * tope o que una pendiente plana:
 *
 *   · si alguien añade otra consulta por moto, pasa a dos y falla;
 *   · si alguien «arregla» la de la posición volviendo a la consulta única,
 *     pasa a cero y también falla — porque deshacer esa decisión tiene que ser
 *     deliberado y no un efecto secundario.
 *
 * Una pendiente plana sería una afirmación falsa aquí: diría que la ruta no
 * crece con la flota, y crece a propósito.
 */
function flotaConPosiciones(int $cuantos): array
{
    foreach ([2 => 'tendero', 3 => 'domiciliario'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La de la torre', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        'latitude' => 11.0, 'longitude' => -74.8,
    ], 'busines_id');

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId([
        'user_id' => $tendero->user_id, 'state' => 1,
    ], 'owner_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
    ]);

    for ($i = 0; $i < $cuantos; $i++) {
        $u = User::factory()->create(['rol' => 3]);
        $did = DB::table('domiciliary')->insertGetId([
            'user_id' => $u->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1,
        ], 'domiciliary_id');
        DB::table('business_domiciliary')->insert([
            'busines_id' => $negocio, 'domiciliary_id' => $did, 'state' => 1,
        ]);

        // Dos posiciones, para que la consulta tenga de dónde elegir la última.
        foreach ([30, 5] as $haceMinutos) {
            DB::table('order_geolocation')->insert([
                'domiciliary_id' => $did,
                'latitude' => 11.0 + ($i / 1000),
                'longitude' => -74.8,
                'created_at' => now()->subMinutes($haceMinutos),
                'updated_at' => now()->subMinutes($haceMinutos),
            ]);
        }
    }

    return ['negocio' => (int) $negocio, 'tendero' => $tendero];
}

it('cuesta exactamente una consulta mas por cada moto de la flota', function () {
    $medir = function (int $cuantos) {
        $e = flotaConPosiciones($cuantos);
        Sanctum::actingAs($e['tendero']);

        // Calentar: `platform_settings` se cachea por proceso y la primera
        // petición paga una consulta que ninguna otra paga.
        test()->getJson('/v1/negocio/torre')->assertOk();

        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });

        test()->getJson('/v1/negocio/torre')->assertOk();

        return $n;
    };

    $tres = $medir(3);
    $nueve = $medir(9);

    /*
     * Se afirma la DIFERENCIA y no el cociente: en PHP una división exacta
     * devuelve `int`, así que `toBe(1.0)` falla con «1 is identical to 1.0».
     * Seis motos más, seis consultas más.
     */
    expect($nueve - $tres)->toBe(6);
});

it('la pantalla sigue trayendo el saldo y la posicion de cada uno', function () {
    $e = flotaConPosiciones(3);
    Sanctum::actingAs($e['tendero']);

    $r = $this->getJson('/v1/negocio/torre')->assertOk();

    $torre = $r->json('flota');
    expect($torre)->toHaveCount(3);

    foreach ($torre as $moto) {
        // El saldo viene del cálculo por lotes; antes de una en una. Tiene que
        // seguir estando, y numérico.
        expect($moto)->toHaveKeys(['domiciliary_id', 'name', 'efectivo', 'posicion']);
        expect($moto['efectivo'])->toBeNumeric();

        // Y la posición, que es la consulta por moto que se conserva: la ÚLTIMA
        // de las dos que tiene cada uno.
        expect($moto['posicion'])->not->toBeNull();
        expect($moto['posicion']['hace_minutos'])->toBeLessThan(10);
    }
});

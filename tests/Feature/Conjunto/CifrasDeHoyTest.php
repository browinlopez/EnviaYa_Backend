<?php

use App\Models\Conjunto\ComplexStaff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LAS CIFRAS DE HOY CUADRAN: «Entradas 5» = 3 entradas de domiciliarios
 * (de 2 personas distintas) + 2 anotadas.
 */
test('entradas, domiciliarios distintos, sus entradas y las anotadas', function () {
    Carbon::setTestNow('2026-11-20 15:00:00');
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    $a = repartidorConPedidoEn(null, '1090000001')->domiciliary_id;
    $b = repartidorConPedidoEn(null, '1090000002')->domiciliary_id;

    $fila = fn (array $d) => $d + [
        'complex_id' => $c['complexId'], 'orders_count' => 0, 'method' => 'codigo', 'domiciliary_id' => null,
        'created_at' => now()->subHour(), 'updated_at' => now(),
    ];
    DB::table('complex_entries')->insert([
        $fila(['kind' => 'domiciliario', 'domiciliary_id' => $a]),
        $fila(['kind' => 'domiciliario', 'domiciliary_id' => $a]),
        $fila(['kind' => 'domiciliario', 'domiciliary_id' => $b]),
        $fila(['kind' => 'visitante', 'method' => 'manual']),
        $fila(['kind' => 'servicio', 'method' => 'manual']),
    ]);

    Sanctum::actingAs($c['user']);
    $hoy = $this->getJson('/v1/conjunto/resumen')->assertOk()->json('hoy');

    expect($hoy)->toMatchArray([
        'entradas' => 5,
        'domiciliarios' => 2,
        'entradas_domiciliarios' => 3,
        'externos' => 2,
    ]);

    Carbon::setTestNow();
});

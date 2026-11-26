<?php

use App\Models\Conjunto\ComplexStaff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * «Por celador» en el reporte: todos los del conjunto, también los que no
 * anotaron nada, y ninguno de otro edificio.
 */
test('el reporte trae a cada celador, tambien al que no registro nada', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    $activo = User::factory()->create(['rol' => 6, 'name' => 'Yesenia']);
    $quieto = User::factory()->create(['rol' => 6, 'name' => 'Jhon']);
    foreach ([$activo, $quieto] as $u) {
        ComplexStaff::create(['user_id' => $u->user_id, 'complex_id' => $c['complexId'], 'role' => ComplexStaff::CELADOR, 'state' => true]);
    }

    // Uno de otro conjunto: no sale.
    $otro = conjuntoConPersonal(ComplexStaff::CELADOR);

    $domi = repartidorConPedidoEn($c['complexId']);
    DB::table('complex_entries')->insert([
        ['complex_id' => $c['complexId'], 'kind' => 'domiciliario', 'domiciliary_id' => $domi->domiciliary_id,
            'method' => 'codigo', 'orders_count' => 1, 'registered_by' => $activo->user_id,
            'exited_at' => null, 'exit_registered_by' => null, 'visitor_name' => null,
            'created_at' => now()->subHour(), 'updated_at' => now()],
        ['complex_id' => $c['complexId'], 'kind' => 'domiciliario', 'domiciliary_id' => $domi->domiciliary_id,
            'method' => 'cedula', 'orders_count' => 1, 'registered_by' => $activo->user_id,
            'exited_at' => now(), 'exit_registered_by' => $quieto->user_id, 'visitor_name' => null,
            'created_at' => now()->subHours(2), 'updated_at' => now()],
    ]);

    $p = collect($this->getJson('/v1/conjunto/reportes')->assertOk()->json('libro.por_celador'));

    expect($p->pluck('nombre')->all())->toBe(['Yesenia', 'Jhon'])
        ->and($p[0]['entradas'])->toBe(2)
        ->and($p[0]['por_qr'])->toEqual(0.5)
        ->and($p[0]['salidas'])->toBe(0)
        ->and($p[1]['entradas'])->toBe(0)
        ->and($p[1]['por_qr'])->toBeNull()
        ->and($p[1]['salidas'])->toBe(1)
        ->and($p[1]['ultima_actividad'])->not->toBeNull()
        ->and($p->pluck('nombre'))->not->toContain($otro['user']->name);
});

test('la serie del libro trae los pedidos de cada dia', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);
    repartidorConPedidoEn($c['complexId']);

    $serie = collect($this->getJson('/v1/conjunto/reportes')->assertOk()->json('libro.por_dia'));

    expect($serie->last())->toHaveKey('pedidos')
        ->and($serie->sum('pedidos'))->toBe(1)
        ->and($serie->firstWhere('dia', now()->toDateString())['pedidos'])->toBe(1);
});

<?php

use App\Models\Rol;
use App\Models\User;
use Database\Seeders\DemostracionQaSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/BaseDeQa.php';

// Lo que se enseña con la demo sale lleno y coherente: el inversionista, el dueño y la portería.
beforeEach(function () {
    baseDeQa();
    (new DemostracionQaSeeder)->run();
});

it('cada módulo del inversionista tiene cifras que enseñar', function () {
    Rol::firstOrCreate(['rol_id' => 7], ['name' => 'inversionista', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 7]));

    $resumen = $this->getJson('/v1/inversionista/resumen')->assertOk()->json();
    $negocios = $this->getJson('/v1/inversionista/negocios')->assertOk()->json();
    $expansion = $this->getJson('/v1/inversionista/expansion')->assertOk()->json();
    $usuarios = $this->getJson('/v1/inversionista/usuarios')->assertOk()->json();
    $operacion = $this->getJson('/v1/inversionista/operacion?desde=2026-08-30&hasta=2026-11-27')->assertOk()->json();
    $meses = array_column($resumen['meses'], 'pedidos');

    expect($resumen['cifras']['pedidos']['valor'])->toBeGreaterThan(300)
        ->and($resumen['cifras']['pedidos']['variacion'])->not->toBeNull()
        ->and($resumen['cifras']['negocios_activos']['con_pedidos'])->toBeGreaterThanOrEqual(6)
        ->and(collect($resumen['por_tipo'])->where('pedidos', '>', 0)->count())->toBe(4)
        ->and(count(array_filter($meses)))->toBeGreaterThanOrEqual(10)
        ->and($meses[count($meses) - 2])->toBeGreaterThan($meses[count($meses) - 8])
        ->and($negocios['actividad']['con_ventas']['valor'])->toBeGreaterThanOrEqual(6)
        ->and($negocios['arranque']['medidos'])->toBeGreaterThanOrEqual(6)
        ->and($negocios['arranque']['en_espera'])->toBeGreaterThanOrEqual(1)
        ->and(array_sum(array_column($negocios['meses'], 'negocios_nuevos')))->toBeGreaterThanOrEqual(7)
        ->and($expansion['resumen']['ciudades_sin_oferta'])->toBeGreaterThanOrEqual(2)
        ->and(collect($expansion['por_ciudad'])->firstWhere('ciudad', 'Puerto Colombia')['esperando'])->toBeTrue()
        ->and(collect($expansion['por_ciudad'])->firstWhere('ciudad', 'Barranquilla')['radio_km'])->not->toBeNull()
        ->and(count($expansion['calor']['celdas']))->toBeGreaterThanOrEqual(5)
        ->and($usuarios['cifras']['activos']['valor'])->toBeGreaterThan(50)
        ->and($usuarios['cifras']['recompra']['valor'])->toBeGreaterThan(30)
        ->and($operacion['cifras']['minutos_en_camino']['valor'])->toBeGreaterThan(8)->toBeLessThan(30)
        ->and($operacion['cifras']['a_tiempo']['valor'])->toBeGreaterThan(75)
        ->and($operacion['cifras']['porcentaje_cancelados']['valor'])->toBeGreaterThan(1)->toBeLessThan(10)
        ->and($operacion['cifras']['domiciliarios_activos']['valor'])->toBeGreaterThanOrEqual(6);
});

it('el dueño de tres negocios ve las ventas de los tres y el celador ve quién sigue adentro', function () {
    $jorge = User::where('email', 'jorge.luis.fontalvo@demo.vecipaya.com')->firstOrFail();
    Sanctum::actingAs($jorge);
    $ventas = $this->getJson('/v1/negocio/mis-negocios/ventas')->assertOk()->json();

    $celador = User::where('rol', 6)->where('email', 'like', '%@demo.vecipaya.com')->firstOrFail();
    Sanctum::actingAs($celador);
    $adentro = $this->getJson('/v1/conjunto/porteria/adentro')->assertOk()->json();

    expect($ventas['negocios'])->toHaveCount(3)
        ->and(collect($ventas['negocios'])->every(fn ($n) => $n['total'] > 0 && $n['ventas_mostrador'] > 0))->toBeTrue()
        ->and(count($adentro['data'] ?? $adentro))->toBeGreaterThanOrEqual(3)
        ->and(DB::table('complex_entries')->whereNotNull('orders')->count())->toBeGreaterThan(30);
});

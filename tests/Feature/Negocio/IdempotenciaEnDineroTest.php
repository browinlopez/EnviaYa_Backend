<?php

declare(strict_types=1);

use App\Models\IdempotencyKey;
use App\Models\Operacion\CashDeposit;
use App\Models\Operacion\CashMovement;
use App\Models\Operacion\StoreCredit;
use App\Models\Operacion\StoreCreditMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function idemTiendaConPony(): array
{
    Carbon::setTestNow('2026-11-24 12:00:00');
    $e = escenarioDeTope(null);

    $pony = DB::table('products')->insertGetId(['name' => 'Pony Malta', 'state' => 1], 'products_id');
    DB::table('products_business')->insert([
        'busines_id' => $e['businessId'], 'products_id' => $pony, 'price' => 2500,
        'amount' => 10, 'sale_unit' => 'unidad', 'stock_weight' => 0, 'por_valor' => 0,
    ]);

    Sanctum::actingAs($e['tendero']);

    return $e + ['pony' => $pony];
}

function idemVenta(int $pony, int $unidades = 2, int $pagaCon = 10000): array
{
    return ['lineas' => [['products_id' => $pony, 'amount' => $unidades]], 'cash_tendered' => $pagaCon];
}

function idemPonyQueQuedan(int $pony): int
{
    return (int) DB::table('products_business')->where('products_id', $pony)->value('amount');
}

afterEach(fn () => Carbon::setTestNow());

it('la misma venta con la misma clave se anota una vez y repite la respuesta', function () {
    $e = idemTiendaConPony();
    $clave = (string) Str::uuid();

    $primera = $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony']), ['Idempotency-Key' => $clave])
        ->assertCreated();
    $segunda = $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony']), ['Idempotency-Key' => $clave])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($segunda->json())->toEqual($primera->json())
        ->and(DB::table('ventas_mostrador')->count())->toBe(1)
        ->and(idemPonyQueQuedan($e['pony']))->toBe(8);
});

it('la misma clave con otra venta se rechaza y no anota nada más', function () {
    $e = idemTiendaConPony();
    $clave = (string) Str::uuid();

    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony']), ['Idempotency-Key' => $clave])
        ->assertCreated();
    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony'], 3), ['Idempotency-Key' => $clave])
        ->assertStatus(422)
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

    expect(DB::table('ventas_mostrador')->count())->toBe(1)
        ->and(idemPonyQueQuedan($e['pony']))->toBe(8);
});

it('sin clave todo sigue como antes: dos envíos son dos ventas', function () {
    $e = idemTiendaConPony();

    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony']))->assertCreated();
    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony']))->assertCreated();

    expect(DB::table('ventas_mostrador')->count())->toBe(2)
        ->and(idemPonyQueQuedan($e['pony']))->toBe(6)
        ->and(IdempotencyKey::count())->toBe(0);
});

it('una clave que no es UUID se rechaza sin anotar la venta', function () {
    $e = idemTiendaConPony();

    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony']), ['Idempotency-Key' => 'abc'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('idempotency_key');

    expect(DB::table('ventas_mostrador')->count())->toBe(0);
});

it('mientras la primera sigue en curso, la segunda recibe 409 y no ejecuta', function () {
    $e = idemTiendaConPony();
    $clave = (string) Str::uuid();
    IdempotencyKey::create([
        'user_id' => $e['tendero']->user_id, 'idempotency_key' => $clave, 'method' => 'POST',
        'path' => 'v1/negocio/ventas-mostrador', 'fingerprint' => str_repeat('0', 64),
        'expires_at' => now()->addHours(24),
    ]);

    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony']), ['Idempotency-Key' => $clave])
        ->assertStatus(409)
        ->assertJsonPath('code', 'IDEMPOTENCY_IN_PROGRESS');

    expect(DB::table('ventas_mostrador')->count())->toBe(0);
});

it('una venta rechazada no gasta la clave: el reintento corregido entra', function () {
    $e = idemTiendaConPony();
    $clave = (string) Str::uuid();

    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony'], 2, 2000), ['Idempotency-Key' => $clave])
        ->assertStatus(422);
    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony']), ['Idempotency-Key' => $clave])
        ->assertCreated();

    expect(DB::table('ventas_mostrador')->count())->toBe(1);
});

it('a las 24 horas la clave vence y vuelve a ejecutar', function () {
    $e = idemTiendaConPony();
    $clave = (string) Str::uuid();

    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony']), ['Idempotency-Key' => $clave])
        ->assertCreated();
    Carbon::setTestNow('2026-11-25 12:00:01');
    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony']), ['Idempotency-Key' => $clave])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(DB::table('ventas_mostrador')->count())->toBe(2)
        ->and(IdempotencyKey::count())->toBe(1);
});

it('la clave es de cada usuario: otra tienda con la misma clave no recibe la venta ajena', function () {
    $e = idemTiendaConPony();
    $clave = (string) Str::uuid();
    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($e['pony']), ['Idempotency-Key' => $clave])
        ->assertCreated();

    $otra = idemTiendaConPony();
    $this->postJson('/v1/negocio/ventas-mostrador', idemVenta($otra['pony']), ['Idempotency-Key' => $clave])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(DB::table('ventas_mostrador')->count())->toBe(2)
        ->and(idemPonyQueQuedan($otra['pony']))->toBe(8);
});

it('el mismo abono al fiado con la misma clave libera el cupo una sola vez', function () {
    $e = idemTiendaConPony();
    $cliente = User::factory()->create(['rol' => 1]);
    $credito = StoreCredit::create([
        'busines_id' => $e['businessId'], 'user_id' => $cliente->user_id, 'credit_limit' => 50000,
    ]);
    StoreCreditMovement::create([
        'store_credit_id' => $credito->id, 'type' => StoreCreditMovement::CONSUMO, 'amount' => 20000,
    ]);
    $clave = (string) Str::uuid();

    foreach ([1, 2] as $_) {
        $this->postJson("/v1/negocio/creditos/{$cliente->user_id}/abonos", ['amount' => 5000], ['Idempotency-Key' => $clave])
            ->assertCreated()
            ->assertJsonPath('cupo.usado', 15000);
    }

    expect(StoreCreditMovement::where('type', StoreCreditMovement::ABONO)->count())->toBe(1);
});

it('el mismo efectivo entregado con la misma clave se cuenta una vez', function () {
    $e = idemTiendaConPony();
    DB::table('payment_methods')->insertOrIgnore(['methods_id' => 1, 'name' => 'Efectivo', 'state' => 1]);
    $pedido = DB::table('orderssales')->insertGetId([
        'busines_id' => $e['businessId'], 'domiciliary_id' => $e['domiId'], 'state' => 4, 'methods_id' => 1,
        'subtotal' => 30000, 'domicilio' => 0, 'domiciliary_fee' => 0, 'discount' => 0,
        'total' => 30000, 'cash_due' => 30000, 'sale_date' => now()->subDay(),
    ], 'orderSales_id');
    CashMovement::create([
        'domiciliary_id' => $e['domiId'], 'type' => CashMovement::RECAUDO, 'amount' => 30000, 'order_id' => $pedido,
    ]);
    $clave = (string) Str::uuid();

    foreach ([1, 2] as $_) {
        $this->postJson("/v1/negocio/efectivo/{$e['domiId']}/entregas", ['amount' => 20000], ['Idempotency-Key' => $clave])
            ->assertCreated()
            ->assertJsonPath('pendiente', 10000);
    }

    expect(CashDeposit::where('domiciliary_id', $e['domiId'])->count())->toBe(1);
});

it('el mismo gasto con la misma clave se anota una vez', function () {
    idemTiendaConPony();
    $clave = (string) Str::uuid();
    $gasto = ['tipo' => 'arriendo', 'monto' => 800000, 'frecuencia' => 'mensual'];

    $this->postJson('/v1/negocio/gastos', $gasto, ['Idempotency-Key' => $clave])->assertCreated();
    $this->postJson('/v1/negocio/gastos', $gasto, ['Idempotency-Key' => $clave])->assertCreated();

    expect(DB::table('gastos_del_negocio')->count())->toBe(1);
});

it('las cinco rutas de dinero del panel llevan el middleware', function () {
    $conClave = collect(Route::getRoutes())
        ->filter(fn ($r) => in_array('idempotente', $r->gatherMiddleware(), true))
        ->map(fn ($r) => implode('|', array_diff($r->methods(), ['HEAD'])).' '.$r->uri())
        ->sort()->values()->all();

    expect($conClave)->toBe([
        'POST v1/negocio/creditos/{userId}/abonos',
        'POST v1/negocio/efectivo/{id}/entregas',
        'POST v1/negocio/gastos',
        'POST v1/negocio/ventas-mostrador',
        'POST v1/negocio/ventas-mostrador/{id}/cancelar',
    ]);
});

it('la poda borra solo las claves vencidas', function () {
    $e = idemTiendaConPony();
    foreach (['vencida' => -1, 'viva' => 1] as $nombre => $horas) {
        IdempotencyKey::create([
            'user_id' => $e['tendero']->user_id, 'idempotency_key' => (string) Str::uuid(), 'method' => 'POST',
            'path' => $nombre, 'fingerprint' => str_repeat('0', 64), 'expires_at' => now()->addHours($horas),
        ]);
    }

    $this->artisan('model:prune', ['--model' => [IdempotencyKey::class]])->assertSuccessful();

    expect(IdempotencyKey::pluck('path')->all())->toBe(['viva']);
});

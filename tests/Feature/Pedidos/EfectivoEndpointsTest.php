<?php

use App\Models\Area;
use App\Models\Operacion\CashDeposit;
use App\Models\Rol;
use App\Models\User;
use App\Services\CustodiaDeEfectivo;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Los dos lados del efectivo por HTTP: el domiciliario que consulta y declara,
 * y el equipo que confirma.
 *
 * Lo que más importa acá es que la identidad salga de la SESIÓN y no de un
 * parámetro. Con un identificador en la ruta, cualquiera podría mirar —o
 * intentar saldar— el saldo de otro, que es el mismo error que ya se había
 * corregido en los pedidos.
 */

function repartidorConSaldo(float $monto = 32000): array
{
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 3]);
    $domiId = DB::table('domiciliary')->insertGetId([
        'user_id' => $user->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    if ($monto > 0) {
        DB::table('cash_movements')->insert([
            'domiciliary_id' => $domiId, 'type' => 'recaudo',
            'amount' => $monto, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return compact('user', 'domiId');
}

function contable(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $area = Area::where('code', 'sistema')->first();
    $user = User::factory()->create(['rol' => 4]);
    $user->area_id      = $area->id;
    $user->access_level = Area::NIVEL_GESTOR;
    $user->save();

    return $user;
}

/* ---------------------------------------------------------------------- */

test('el domiciliario ve su saldo y sus movimientos', function () {
    $e = repartidorConSaldo(32000);
    Sanctum::actingAs($e['user']);

    $this->getJson('/v1/domiciliaries/cash-balance')
        ->assertOk()
        ->assertJsonPath('balance', 32000);
});

test('el saldo sale de la sesion, no de un parametro', function () {
    // Si se pudiera pedir por identificador, cualquiera vería el de otro.
    $mio  = repartidorConSaldo(1000);
    $otro = repartidorConSaldo(99000);

    Sanctum::actingAs($mio['user']);

    $this->getJson('/v1/domiciliaries/cash-balance?domiciliary_id=' . $otro['domiId'])
        ->assertOk()
        ->assertJsonPath('balance', 1000);
});

test('quien no es domiciliario no tiene saldo que ver', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $this->getJson('/v1/domiciliaries/cash-balance')->assertStatus(403);
});

test('declarar una consignacion la deja pendiente y no baja el saldo', function () {
    $e = repartidorConSaldo(32000);
    Sanctum::actingAs($e['user']);

    $this->postJson('/v1/domiciliaries/deposits', [
        'amount' => 32000, 'reference' => 'ABC123',
    ])
        ->assertCreated()
        // Sigue debiendo: declarar no es entregar.
        ->assertJsonPath('balance', 32000);

    expect(CashDeposit::first()->state)->toBe(CashDeposit::PENDIENTE);
});

test('no se declara una consignacion sin monto', function () {
    $e = repartidorConSaldo(32000);
    Sanctum::actingAs($e['user']);

    $this->postJson('/v1/domiciliaries/deposits', ['reference' => 'ABC'])
        ->assertStatus(422);
});

test('confirmar desde el panel baja el saldo', function () {
    $e = repartidorConSaldo(32000);
    $deposito = app(CustodiaDeEfectivo::class)
        ->declararDeposito($e['domiId'], ['amount' => 32000, 'reference' => 'ABC']);

    Sanctum::actingAs(contable());

    $this->putJson("/v1/admin/cash-deposits/{$deposito->id}", ['state' => 'confirmada'])
        ->assertOk()
        ->assertJsonPath('balance', 0);
});

test('rechazar no toca el saldo', function () {
    $e = repartidorConSaldo(32000);
    $deposito = app(CustodiaDeEfectivo::class)
        ->declararDeposito($e['domiId'], ['amount' => 32000]);

    Sanctum::actingAs(contable());

    $this->putJson("/v1/admin/cash-deposits/{$deposito->id}", [
        'state' => 'rechazada', 'notes' => 'La referencia no aparece',
    ])
        ->assertOk()
        ->assertJsonPath('balance', 32000);
});

test('confirmar dos veces responde 422 y no borra la deuda', function () {
    $e = repartidorConSaldo(32000);
    $deposito = app(CustodiaDeEfectivo::class)
        ->declararDeposito($e['domiId'], ['amount' => 32000]);

    Sanctum::actingAs(contable());

    $this->putJson("/v1/admin/cash-deposits/{$deposito->id}", ['state' => 'confirmada'])->assertOk();
    $this->putJson("/v1/admin/cash-deposits/{$deposito->id}", ['state' => 'confirmada'])->assertStatus(422);

    expect(app(CustodiaDeEfectivo::class)->saldo($e['domiId']))->toBe(0.0);
});

test('el panel lista quien debe cuanto', function () {
    $a = repartidorConSaldo(30000);
    $b = repartidorConSaldo(12000);
    repartidorConSaldo(0); // sin deuda: no debe aparecer

    Sanctum::actingAs(contable());

    $this->getJson('/v1/admin/cash-balances')
        ->assertOk()
        ->assertJsonPath('total', 42000)
        ->assertJsonCount(2, 'data');
});

test('un domiciliario no puede confirmar sus propios depositos', function () {
    // Sería saldar su propia deuda con un clic.
    $e = repartidorConSaldo(32000);
    $deposito = app(CustodiaDeEfectivo::class)
        ->declararDeposito($e['domiId'], ['amount' => 32000]);

    Sanctum::actingAs($e['user']);

    $this->putJson("/v1/admin/cash-deposits/{$deposito->id}", ['state' => 'confirmada'])
        ->assertStatus(403);

    expect(app(CustodiaDeEfectivo::class)->saldo($e['domiId']))->toBe(32000.0);
});

test('la nota de quien confirma se guarda', function () {
    /*
     * El endpoint validaba `notes` y solo la pasaba al rechazar: al confirmar
     * se descartaba en silencio. Quien revisa el extracto escribe por qué da
     * por buena la consignación —«extracto del 5, movimiento 4482910»— y esa
     * frase es todo el sustento que queda si mañana la cifra se discute.
     */
    $e = repartidorConSaldo(24000);
    $deposito = app(CustodiaDeEfectivo::class)
        ->declararDeposito($e['domiId'], ['amount' => 24000, 'reference' => '4482910']);

    Sanctum::actingAs(contable());

    $this->putJson("/v1/admin/cash-deposits/{$deposito->id}", [
        'state' => 'confirmada',
        'notes' => 'Verificado contra el extracto del 5.',
    ])->assertOk();

    expect($deposito->fresh()->notes)->toBe('Verificado contra el extracto del 5.');
});

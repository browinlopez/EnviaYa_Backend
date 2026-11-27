<?php

use App\Models\Conjunto\ComplexStaff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * «El mes pasado» es una ventana CERRADA: del primero al último segundo del
 * mes anterior. Las demás llegan hasta ahora; esta no puede contar lo de
 * este mes.
 */
function domicilioALa502(int $complexId, int $domiId, string $cuando): void
{
    DB::table('complex_entries')->insert([
        'complex_id' => $complexId, 'kind' => 'domiciliario', 'domiciliary_id' => $domiId,
        'method' => 'codigo', 'orders_count' => 1,
        'orders' => json_encode([['tower' => '3', 'apartment' => '502']]),
        'created_at' => $cuando, 'updated_at' => $cuando,
    ]);
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-15 10:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

test('mes_pasado cuenta solo el mes anterior y dice hasta cuando', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);
    $domi = repartidorConPedidoEn($c['complexId']);

    // El vecino de la 502 que dejó el pedido, vinculado al conjunto.
    $buyerId = DB::table('buyer')->latest('buyer_id')->value('buyer_id');
    DB::table('buyer_complex')->insert(['buyer_id' => $buyerId, 'complex_id' => $c['complexId']]);

    domicilioALa502($c['complexId'], $domi->domiciliary_id, '2026-09-30 23:00:00'); // antes
    domicilioALa502($c['complexId'], $domi->domiciliary_id, '2026-10-01 00:10:00'); // dentro
    domicilioALa502($c['complexId'], $domi->domiciliary_id, '2026-10-31 23:59:00'); // dentro
    domicilioALa502($c['complexId'], $domi->domiciliary_id, '2026-11-01 00:01:00'); // después

    $r = $this->getJson('/v1/conjunto/residentes?ventana=mes_pasado')->assertOk();

    expect($r->json('resumen.ventana'))->toBe('mes_pasado')
        ->and($r->json('resumen.desde'))->toBe('2026-10-01')
        ->and($r->json('resumen.hasta'))->toBe('2026-10-31')
        ->and($r->json('resumen.domicilios'))->toBe(2);

    $d = $this->getJson('/v1/conjunto/residentes/detalle?ventana=mes_pasado&tower=3')->assertOk();

    expect($d->json('meta.hasta'))->toBe('2026-10-31')
        ->and(collect($d->json('data'))->firstWhere('apartment', '502')['domicilios'])->toBe(2);
});

test('las ventanas abiertas siguen sin fecha de fin', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);
    $domi = repartidorConPedidoEn($c['complexId']);
    domicilioALa502($c['complexId'], $domi->domiciliary_id, '2026-11-14 09:00:00');

    $r = $this->getJson('/v1/conjunto/residentes?ventana=mes')->assertOk();

    expect($r->json('resumen.hasta'))->toBeNull()
        ->and($r->json('resumen.domicilios'))->toBe(1);
});

test('entre dos fechas del calendario cuenta solo ese rango, en la cifra y en la torre', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);
    $domi = repartidorConPedidoEn($c['complexId']);
    $buyerId = DB::table('buyer')->latest('buyer_id')->value('buyer_id');
    DB::table('buyer_complex')->insert(['buyer_id' => $buyerId, 'complex_id' => $c['complexId']]);

    domicilioALa502($c['complexId'], $domi->domiciliary_id, '2026-11-02 23:50:00'); // antes
    domicilioALa502($c['complexId'], $domi->domiciliary_id, '2026-11-03 08:00:00'); // dentro
    domicilioALa502($c['complexId'], $domi->domiciliary_id, '2026-11-09 23:30:00'); // dentro
    domicilioALa502($c['complexId'], $domi->domiciliary_id, '2026-11-10 07:00:00'); // después

    $r = $this->getJson('/v1/conjunto/residentes?desde=2026-11-03&hasta=2026-11-09')->assertOk();

    expect($r->json('resumen.ventana'))->toBe('rango')
        ->and($r->json('resumen.desde'))->toBe('2026-11-03')
        ->and($r->json('resumen.hasta'))->toBe('2026-11-09')
        ->and($r->json('resumen.domicilios'))->toBe(2);

    $d = $this->getJson('/v1/conjunto/residentes/detalle?desde=2026-11-03&hasta=2026-11-09&tower=3')->assertOk();

    expect($d->json('meta.desde'))->toBe('2026-11-03')
        ->and(collect($d->json('data'))->firstWhere('apartment', '502')['domicilios'])->toBe(2);

    // Sin rango manda la ventana de siempre, y un nombre raro cae en 30 días.
    expect($this->getJson('/v1/conjunto/residentes?ventana=cualquiera')->assertOk()->json('resumen.ventana'))
        ->toBe('30');
    $this->getJson('/v1/conjunto/residentes?desde=2026-11-09&hasta=2026-11-03')
        ->assertStatus(422)->assertJsonValidationErrors('hasta');
});

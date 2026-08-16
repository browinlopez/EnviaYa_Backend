<?php

use App\Models\Area;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Operacion\Pqrs;
use App\Models\Operacion\SafetyIncident;
use App\Models\Operacion\Settlement;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * SST, Calidad y Contabilidad.
 *
 * Se prueban las reglas que impiden que estos módulos se conviertan en un
 * registro decorativo: que un incidente no se cierre sin acciones, que un PQRS
 * no se resuelva sin respuesta, que una liquidación no cuente el mismo pedido
 * dos veces, y que cada área alcance lo suyo y nada más.
 */

function comoArea2(string $codigo): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
    $area = Area::where('code', $codigo)->firstOrFail();

    $u = User::factory()->create([
        'rol' => 4, 'area_id' => $area->id, 'access_level' => 'gestor',
    ]);
    Sanctum::actingAs($u);

    return $u;
}

function domiciliarioDePrueba(): int
{
    // El rol se asegura acá y no solo en `comoArea2`: hay pruebas de modelo que
    // necesitan un domiciliario sin autenticarse contra el panel.
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 3]);

    return DB::table('domiciliary')->insertGetId([
        'user_id' => $user->user_id,
        'available' => 1,
        'qualification' => 0,
        'state' => 1,
    ], 'domiciliary_id');
}

/* ==================== SST · DOCUMENTACIÓN ==================== */

test('clasifica los documentos por vigencia', function () {
    $d = domiciliarioDePrueba();

    $vencido = DomiciliaryDocument::create([
        'domiciliary_id' => $d, 'type' => 'soat',
        'expires_at' => now()->subDays(5)->toDateString(),
    ]);

    $porVencer = DomiciliaryDocument::create([
        'domiciliary_id' => $d, 'type' => 'licencia',
        'expires_at' => now()->addDays(10)->toDateString(),
    ]);

    $vigente = DomiciliaryDocument::create([
        'domiciliary_id' => $d, 'type' => 'tecnomecanica',
        'expires_at' => now()->addMonths(8)->toDateString(),
    ]);

    // La cédula no caduca: sin fecha no entra en las alertas, en vez de
    // obligar a inventarle un vencimiento.
    $sinFecha = DomiciliaryDocument::create([
        'domiciliary_id' => $d, 'type' => 'cedula',
    ]);

    expect($vencido->situacion())->toBe('vencido')
        ->and($porVencer->situacion())->toBe('por_vencer')
        ->and($vigente->situacion())->toBe('vigente')
        ->and($sinFecha->situacion())->toBe('sin_vencimiento');

    expect($vencido->diasRestantes())->toBeLessThan(0)
        ->and($sinFecha->diasRestantes())->toBeNull();
});

test('el tablero cuenta domiciliarios sin la papelería completa', function () {
    comoArea2('sst');
    $d = domiciliarioDePrueba();

    // Solo tiene dos de los cinco obligatorios.
    DomiciliaryDocument::create(['domiciliary_id' => $d, 'type' => 'licencia', 'expires_at' => now()->addYear()]);
    DomiciliaryDocument::create(['domiciliary_id' => $d, 'type' => 'soat', 'expires_at' => now()->addYear()]);

    $r = $this->getJson('/v1/admin/sst/documentos')->assertOk();

    expect($r->json('summary.domiciliarios_incompletos'))->toBe(1)
        ->and($r->json('summary.obligatorios'))->toContain('arl', 'eps', 'tecnomecanica');
});

/* ==================== SST · INCIDENTES ==================== */

test('no deja cerrar un incidente sin registrar acciones', function () {
    comoArea2('sst');
    $d = domiciliarioDePrueba();

    $id = $this->postJson('/v1/admin/sst/incidentes', [
        'domiciliary_id' => $d,
        'occurred_at'    => now()->subDay()->toDateTimeString(),
        'type'           => 'accidente_transito',
        'severity'       => 'moderado',
        'description'    => 'Choque leve en la 45',
    ])->assertCreated()->json('id');

    // Cerrar sin acciones deja un registro que solo sirve para contar cuántos
    // hubo, y el módulo existe para que no se repitan.
    $this->putJson("/v1/admin/sst/incidentes/{$id}", ['state' => SafetyIncident::CERRADO])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Para cerrar un incidente hay que registrar qué acciones se tomaron.');

    $this->putJson("/v1/admin/sst/incidentes/{$id}", [
        'state'   => SafetyIncident::CERRADO,
        'actions' => 'Se reforzó la capacitación en manejo defensivo.',
    ])->assertOk();

    expect(SafetyIncident::find($id)->closed_at)->not->toBeNull();
});

test('el resumen acumula días de incapacidad del año', function () {
    comoArea2('sst');
    $d = domiciliarioDePrueba();

    foreach ([3, 7] as $dias) {
        SafetyIncident::create([
            'domiciliary_id' => $d,
            'occurred_at'    => now()->subDays(10),
            'type'           => 'accidente_transito',
            'severity'       => 'grave',
            'had_injuries'   => true,
            'days_off'       => $dias,
            'description'    => 'x',
        ]);
    }

    $r = $this->getJson('/v1/admin/sst/incidentes')->assertOk();

    // Es el número que piden la ARL y los indicadores de severidad.
    expect($r->json('summary.dias_incapacidad_anio'))->toBe(10)
        ->and($r->json('summary.graves_anio'))->toBe(2);
});

/* ==================== CALIDAD · PQRS ==================== */

test('el radicado es consecutivo y lleva el año', function () {
    comoArea2('calidad');

    $a = $this->postJson('/v1/admin/pqrs', [
        'type' => 'queja', 'subject' => 'Demora', 'description' => 'Llegó tarde',
    ])->assertCreated()->json('code');

    $b = $this->postJson('/v1/admin/pqrs', [
        'type' => 'reclamo', 'subject' => 'Producto', 'description' => 'Faltó un ítem',
    ])->assertCreated()->json('code');

    $anio = now()->year;

    expect($a)->toBe("PQRS-{$anio}-00001")
        ->and($b)->toBe("PQRS-{$anio}-00002");
});

test('el plazo se fija según la prioridad al radicar', function () {
    comoArea2('calidad');

    $id = $this->postJson('/v1/admin/pqrs', [
        'type' => 'reclamo', 'priority' => 'alta',
        'subject' => 'Urgente', 'description' => 'x',
    ])->assertCreated()->json('id');

    $p = Pqrs::find($id);

    expect($p->due_at->diffInDays(now()))->toBeLessThanOrEqual(Pqrs::PLAZOS['alta']);
});

test('bajar la prioridad no mueve el plazo ya fijado', function () {
    comoArea2('calidad');

    $id = $this->postJson('/v1/admin/pqrs', [
        'type' => 'reclamo', 'priority' => 'alta',
        'subject' => 'Urgente', 'description' => 'x',
    ])->assertCreated()->json('id');

    $plazoOriginal = Pqrs::find($id)->due_at;

    $this->putJson("/v1/admin/pqrs/{$id}", ['priority' => 'baja'])->assertOk();

    // Si el plazo se recalculara, subirle o bajarle la urgencia a un caso
    // atrasado lo haría aparecer como si estuviera a tiempo.
    expect(Pqrs::find($id)->due_at->toDateTimeString())->toBe($plazoOriginal->toDateTimeString());
});

test('no deja resolver un PQRS sin escribir la respuesta', function () {
    comoArea2('calidad');

    $id = $this->postJson('/v1/admin/pqrs', [
        'type' => 'queja', 'subject' => 'x', 'description' => 'y',
    ])->assertCreated()->json('id');

    $this->putJson("/v1/admin/pqrs/{$id}", ['state' => Pqrs::RESUELTO])
        ->assertStatus(422);

    $this->putJson("/v1/admin/pqrs/{$id}", [
        'state' => Pqrs::RESUELTO,
        'resolution' => 'Se reembolsó el domicilio.',
    ])->assertOk();

    expect(Pqrs::find($id)->resolved_at)->not->toBeNull();
});

test('la bitácora guarda quién dijo qué', function () {
    $yo = comoArea2('calidad');

    $id = $this->postJson('/v1/admin/pqrs', [
        'type' => 'queja', 'subject' => 'x', 'description' => 'y',
    ])->assertCreated()->json('id');

    $this->postJson("/v1/admin/pqrs/{$id}/notes", [
        'note' => 'Se llamó al cliente, no contestó.',
        'is_internal' => true,
    ])->assertCreated();

    $r = $this->getJson("/v1/admin/pqrs/{$id}")->assertOk();

    expect($r->json('notes'))->toHaveCount(1)
        ->and($r->json('notes.0.user_id'))->toBe((int) $yo->user_id)
        ->and($r->json('notes.0.is_internal'))->toBeTrue();
});

/* ==================== CONTABILIDAD · LIQUIDACIONES ==================== */

/** Un pedido entregado con su desglose ya congelado. */
function pedidoEntregado(int $negocio, int $domiciliario, float $subtotal, float $domicilio, float $comision, float $descuento = 0): int
{
    return DB::table('orderssales')->insertGetId([
        'busines_id'      => $negocio,
        'domiciliary_id'  => $domiciliario,
        'state'           => 4, // entregado
        'subtotal'        => $subtotal,
        'domicilio'       => $domicilio,
        'domiciliary_fee' => $comision,
        'discount'        => $descuento,
        'total'           => $subtotal + $domicilio - $descuento,
        'sale_date'       => now()->subDays(3),
    ], 'orderSales_id');
}

test('liquida a un negocio el subtotal menos el descuento, sin el domicilio', function () {
    comoArea2('contabilidad');

    $negocio = DB::table('business')->insertGetId(['name' => 'Tienda L', 'qualification' => 0, 'state' => 1]);
    $dom = domiciliarioDePrueba();

    pedidoEntregado($negocio, $dom, 20000, 3000, 750, 2000);
    pedidoEntregado($negocio, $dom, 10000, 3000, 750);

    $id = $this->postJson('/v1/admin/settlements', [
        'type' => 'business', 'target_id' => $negocio,
        'period_start' => now()->subDays(10)->toDateString(),
        'period_end'   => now()->toDateString(),
    ])->assertCreated()->json('id');

    $s = Settlement::find($id);

    // El domicilio es del domiciliario: no entra en lo que se le paga al
    // negocio. El descuento sí se resta, porque la promoción salió de su venta.
    expect($s->orders_count)->toBe(2)
        ->and((float) $s->gross)->toBe(30000.0)
        ->and((float) $s->discounts)->toBe(2000.0)
        ->and((float) $s->net_payable)->toBe(28000.0);
});

test('liquida al domiciliario solo su comisión congelada', function () {
    comoArea2('contabilidad');

    $negocio = DB::table('business')->insertGetId(['name' => 'Tienda M', 'qualification' => 0, 'state' => 1]);
    $dom = domiciliarioDePrueba();

    pedidoEntregado($negocio, $dom, 20000, 3000, 750);
    pedidoEntregado($negocio, $dom, 50000, 4000, 1000);

    $id = $this->postJson('/v1/admin/settlements', [
        'type' => 'domiciliary', 'target_id' => $dom,
        'period_start' => now()->subDays(10)->toDateString(),
        'period_end'   => now()->toDateString(),
    ])->assertCreated()->json('id');

    // Se paga `domiciliary_fee`, que quedó fijado al crear cada pedido con el
    // reparto pactado ese día, no recalculado con la comisión de hoy.
    expect((float) Settlement::find($id)->net_payable)->toBe(1750.0);
});

test('un pedido no se liquida dos veces', function () {
    comoArea2('contabilidad');

    $negocio = DB::table('business')->insertGetId(['name' => 'Tienda N', 'qualification' => 0, 'state' => 1]);
    $dom = domiciliarioDePrueba();
    pedidoEntregado($negocio, $dom, 10000, 3000, 750);

    $rango = [
        'type' => 'business', 'target_id' => $negocio,
        'period_start' => now()->subDays(10)->toDateString(),
        'period_end'   => now()->toDateString(),
    ];

    $this->postJson('/v1/admin/settlements', $rango)->assertCreated();

    // Volver a generar "por si acaso" no puede duplicar el pago.
    $this->postJson('/v1/admin/settlements', $rango)
        ->assertStatus(422)
        ->assertJsonPath('message', 'No hay pedidos entregados sin liquidar en ese periodo.');
});

test('los pedidos no entregados no entran al corte', function () {
    comoArea2('contabilidad');

    $negocio = DB::table('business')->insertGetId(['name' => 'Tienda O', 'qualification' => 0, 'state' => 1]);
    $dom = domiciliarioDePrueba();

    // En camino: todavía puede cancelarse, y pagarlo obligaría a descontarlo
    // del corte siguiente.
    DB::table('orderssales')->insert([
        'busines_id' => $negocio, 'domiciliary_id' => $dom, 'state' => 2,
        'subtotal' => 50000, 'domicilio' => 3000, 'domiciliary_fee' => 750,
        'discount' => 0, 'total' => 53000, 'sale_date' => now()->subDay(),
    ]);

    $this->postJson('/v1/admin/settlements', [
        'type' => 'business', 'target_id' => $negocio,
        'period_start' => now()->subDays(10)->toDateString(),
        'period_end'   => now()->toDateString(),
    ])->assertStatus(422);
});

test('una liquidación aprobada no vuelve a borrador', function () {
    comoArea2('contabilidad');

    $negocio = DB::table('business')->insertGetId(['name' => 'Tienda P', 'qualification' => 0, 'state' => 1]);
    $dom = domiciliarioDePrueba();
    pedidoEntregado($negocio, $dom, 10000, 3000, 750);

    $id = $this->postJson('/v1/admin/settlements', [
        'type' => 'business', 'target_id' => $negocio,
        'period_start' => now()->subDays(10)->toDateString(),
        'period_end'   => now()->toDateString(),
    ])->assertCreated()->json('id');

    $this->putJson("/v1/admin/settlements/{$id}", ['state' => Settlement::APROBADA])->assertOk();

    // Reabrir lo ya aprobado destruiría el valor del corte como constancia.
    $this->putJson("/v1/admin/settlements/{$id}", ['state' => Settlement::BORRADOR])
        ->assertStatus(422);

    // Marcar pagada exige la referencia de la transferencia.
    $this->putJson("/v1/admin/settlements/{$id}", ['state' => Settlement::PAGADA])
        ->assertStatus(422);

    $this->putJson("/v1/admin/settlements/{$id}", [
        'state' => Settlement::PAGADA, 'payment_reference' => 'TRF-99881',
    ])->assertOk();

    expect(Settlement::find($id)->paid_at)->not->toBeNull();
});

/* ==================== PERMISOS DE LOS NUEVOS MÓDULOS ==================== */

test('cada área alcanza sus módulos nuevos y no los ajenos', function () {
    comoArea2('sst');
    $this->getJson('/v1/admin/sst/documentos')->assertOk();
    $this->getJson('/v1/admin/sst/incidentes')->assertOk();
    $this->getJson('/v1/admin/pqrs')->assertForbidden();
    $this->getJson('/v1/admin/settlements')->assertForbidden();

    comoArea2('calidad');
    $this->getJson('/v1/admin/pqrs')->assertOk();
    $this->getJson('/v1/admin/sst/incidentes')->assertOk();     // los consulta
    $this->getJson('/v1/admin/sst/documentos')->assertForbidden(); // no le tocan
    $this->getJson('/v1/admin/settlements')->assertForbidden();

    comoArea2('contabilidad');
    $this->getJson('/v1/admin/settlements')->assertOk();
    $this->getJson('/v1/admin/sst/documentos')->assertForbidden();
    $this->getJson('/v1/admin/pqrs')->assertForbidden();
});

test('Comercial ve los PQRS pero no los gestiona', function () {
    comoArea2('comercial');

    // Las quejas sobre un negocio son información comercial; atenderlas es de
    // Calidad.
    $this->getJson('/v1/admin/pqrs')->assertOk();
    $this->postJson('/v1/admin/pqrs', [
        'type' => 'queja', 'subject' => 'x', 'description' => 'y',
    ])->assertForbidden();
});

test('Gerencia ve los tres módulos nuevos y no cambia nada', function () {
    comoArea2('gerencia');

    $this->getJson('/v1/admin/sst/documentos')->assertOk();
    $this->getJson('/v1/admin/pqrs')->assertOk();
    $this->getJson('/v1/admin/settlements')->assertOk();

    $this->postJson('/v1/admin/pqrs', [
        'type' => 'queja', 'subject' => 'x', 'description' => 'y',
    ])->assertForbidden();
});

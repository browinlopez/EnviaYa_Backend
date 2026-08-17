<?php

use App\Models\Area;
use App\Models\Invoice\Invoice;
use App\Models\Rol;
use App\Models\User;
use App\Services\FacturaService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * COMPROBANTES DE ENTREGA
 *
 * Lo que se comprueba es lo que hace que un registro contable sirva de algo:
 * que no se emita dos veces, que no se emita antes de tiempo, que el número no
 * tenga huecos y que lo anulado deje de contar sin desaparecer.
 */

function comoAreaFactura(string $codigo, string $nivel = Area::NIVEL_GESTOR): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', $codigo)->firstOrFail()->id,
        'access_level' => $nivel,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

function pedidoConEntrega(int $total = 50000, int $estado = 4): int
{
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda de prueba', 'NIT' => '900123456',
        'state' => 1, 'qualification' => 0,
    ], 'busines_id');

    $pedido = (int) DB::table('orderssales')->insertGetId([
        'busines_id'      => $negocio,
        'subtotal'        => $total - 5000,
        'domicilio'       => 5000,
        'discount'        => 0,
        'domiciliary_fee' => 1250,
        'total'           => $total,
        'currency'        => 'COP',
        'sale_date'       => now()->subDay(),
        'delivery_date'   => $estado === 4 ? now() : null,
        'state'           => $estado,
        'payment_state'   => 'approved',
    ], 'orderSales_id');

    $producto = DB::table('products')->insertGetId([
        'name' => 'Arroz 500g', 'category_id' => null, 'state' => 1,
    ], 'products_id');

    DB::table('orderssales_detail')->insert([
        'orderSales_id' => $pedido,
        'product_id'    => $producto,
        'amount'        => 2,
        'unit_price'    => ($total - 5000) / 2,
    ]);

    return $pedido;
}

it('solo emite comprobante de lo ENTREGADO', function () {
    // Un pedido en camino todavía puede cancelarse, y un comprobante de algo
    // que no ocurrió obliga a anularlo después.
    $enCamino = pedidoConEntrega(50000, 3);

    expect(app(FacturaService::class)->emitirPara($enCamino))->toBeNull();
    expect(Invoice::count())->toBe(0);
});

it('emitir dos veces el mismo pedido devuelve el mismo comprobante', function () {
    $pedido = pedidoConEntrega();
    $facturas = app(FacturaService::class);

    $uno = $facturas->emitirPara($pedido);
    $dos = $facturas->emitirPara($pedido);

    /*
     * El disparador vive en el cambio de estado: un reintento del cliente o un
     * doble toque no pueden producir dos comprobantes del mismo pedido.
     */
    expect($dos->invoice_id)->toBe($uno->invoice_id);
    expect(Invoice::count())->toBe(1);
});

it('el consecutivo no tiene huecos y lleva el año', function () {
    $facturas = app(FacturaService::class);
    $numeros = [];

    foreach (range(1, 3) as $i) {
        $numeros[] = $facturas->emitirPara(pedidoConEntrega())->invoice_number;
    }

    $anio = now()->year;

    expect($numeros)->toBe([
        "FV-{$anio}-000001",
        "FV-{$anio}-000002",
        "FV-{$anio}-000003",
    ]);
});

it('guarda una FOTO de los datos, no referencias', function () {
    $pedido = pedidoConEntrega();
    $factura = app(FacturaService::class)->emitirPara($pedido);

    expect($factura->snapshot['negocio']['nombre'])->toBe('Tienda de prueba');
    expect($factura->snapshot['renglones'])->toHaveCount(1);

    // El negocio cambia de nombre DESPUÉS de emitir.
    DB::table('business')->update(['name' => 'Otro nombre completamente']);

    /*
     * La factura de marzo tiene que seguir diciendo lo de marzo. Armada con
     * joins contra las tablas vivas, un cambio de hoy reescribiría el pasado —
     * que es exactamente lo que un comprobante no puede permitir.
     */
    expect($factura->fresh()->snapshot['negocio']['nombre'])->toBe('Tienda de prueba');
});

it('el desglose cuadra con el total', function () {
    $factura = app(FacturaService::class)->emitirPara(pedidoConEntrega(50000));

    // Subtotal + domicilio − descuento = total. Una factura cuyos números no
    // suman no sirve para nada.
    $suma = (float) $factura->subtotal + (float) $factura->domicilio - (float) $factura->descuento;

    expect(round($suma, 2))->toBe(round((float) $factura->total, 2));
});

it('anular conserva el número y registra quién y por qué', function () {
    $yo = comoAreaFactura('contabilidad');
    $factura = app(FacturaService::class)->emitirPara(pedidoConEntrega());
    $numero = $factura->invoice_number;

    $this->putJson("/v1/admin/invoices/{$factura->invoice_id}/anular", [
        'motivo' => 'Se cobró de más el domicilio.',
    ])->assertOk();

    $f = $factura->fresh();

    // No se borra: un hueco en el consecutivo no se puede reconstruir después.
    expect($f->invoice_number)->toBe($numero);
    expect($f->estaAnulada())->toBeTrue();
    expect($f->void_reason)->toBe('Se cobró de más el domicilio.');
    expect((int) $f->voided_by)->toBe((int) $yo->user_id);
});

it('anular exige un motivo', function () {
    comoAreaFactura('contabilidad');
    $factura = app(FacturaService::class)->emitirPara(pedidoConEntrega());

    // Una anulación sin explicación es un agujero que nadie puede reconstruir.
    $this->putJson("/v1/admin/invoices/{$factura->invoice_id}/anular", ['motivo' => ''])
        ->assertStatus(422);

    $this->putJson("/v1/admin/invoices/{$factura->invoice_id}/anular", ['motivo' => 'abc'])
        ->assertStatus(422);
});

it('no se anula dos veces', function () {
    comoAreaFactura('contabilidad');
    $factura = app(FacturaService::class)->emitirPara(pedidoConEntrega());

    $this->putJson("/v1/admin/invoices/{$factura->invoice_id}/anular", ['motivo' => 'Primera vez.'])
        ->assertOk();

    $this->putJson("/v1/admin/invoices/{$factura->invoice_id}/anular", ['motivo' => 'Segunda vez.'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Esta factura ya está anulada.');
});

it('lo anulado no cuenta para lo facturado', function () {
    comoAreaFactura('contabilidad');
    $facturas = app(FacturaService::class);

    $uno = $facturas->emitirPara(pedidoConEntrega(50000));
    $facturas->emitirPara(pedidoConEntrega(30000));

    $facturas->anular($uno, 'Prueba.', null);

    $r = $this->getJson('/v1/admin/invoices?page=1&per_page=10')->assertOk();

    // Contar lo anulado dejaría la caja del panel por encima de la real.
    expect($r->json('summary.total'))->toBe(2);
    expect($r->json('summary.emitidas'))->toBe(1);
    expect($r->json('summary.anuladas'))->toBe(1);
    expect((float) $r->json('summary.facturado'))->toBe(30000.0);
});

it('quien solo consulta no puede anular', function () {
    $factura = app(FacturaService::class)->emitirPara(pedidoConEntrega());

    comoAreaFactura('contabilidad', Area::NIVEL_CONSULTA);

    $this->getJson('/v1/admin/invoices?page=1&per_page=5')->assertOk();

    $this->putJson("/v1/admin/invoices/{$factura->invoice_id}/anular", [
        'motivo' => 'No debería poder.',
    ])->assertForbidden();

    expect($factura->fresh()->estaAnulada())->toBeFalse();
});

it('un área ajena no los ve', function () {
    app(FacturaService::class)->emitirPara(pedidoConEntrega());

    comoAreaFactura('marketing');

    $this->getJson('/v1/admin/invoices?page=1&per_page=5')->assertForbidden();
});

it('el PDF se genera y es un PDF', function () {
    comoAreaFactura('contabilidad');
    $factura = app(FacturaService::class)->emitirPara(pedidoConEntrega());

    $r = $this->get("/v1/admin/invoices/{$factura->invoice_id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    // No es una respuesta en flujo: el PDF se arma completo en memoria y se
    // devuelve de una, que para un comprobante de una página es lo correcto.
    expect(substr($r->getContent(), 0, 5))->toBe('%PDF-');
});

it('el comando recupera las entregas sin comprobante', function () {
    pedidoConEntrega();
    pedidoConEntrega();
    pedidoConEntrega(40000, 3); // en camino: no le toca

    $this->artisan('facturas:emitir')->assertExitCode(0);

    expect(Invoice::count())->toBe(2);

    // Y no duplica al volver a correrlo: es la red de seguridad de que la
    // emisión al entregar va dentro de un try.
    $this->artisan('facturas:emitir')
        ->expectsOutputToContain('Todas las entregas tienen su comprobante')
        ->assertExitCode(0);

    expect(Invoice::count())->toBe(2);
});

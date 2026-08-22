<?php

use App\Http\Controllers\Payment\PaymentController;
use App\Models\Order\OrdersSales;
use App\Models\Rol;
use App\Models\User;
use App\Services\BoldService;
use Illuminate\Support\Facades\DB;

/**
 * Lo que se le manda a la pasarela para abrir una orden de pago.
 *
 * `city` y `province` salen de dos RELACIONES —`municipality` es un belongsTo y
 * `department` un hasOneThrough—, y sin pedirles el `->name` viajaba el modelo
 * completo serializado. Bold responde entonces:
 *
 *     PI_001 — customer: str type expected
 *
 * y no crea la orden de pago, así que el cobro no llega a empezar.
 *
 * Pasó desapercibido mucho tiempo porque las direcciones de prueba no tenían
 * municipio: la relación resolvía a null, null sí lo acepta la pasarela, y los
 * pagos "funcionaban". El fallo aparece justo cuando la dirección está BIEN
 * rellenada, que es el caso de cualquier cliente real.
 */
function pedidoConDireccionCompleta(): OrdersSales
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 1, 'phone' => '3001112233']);
    $buyerId = DB::table('buyer')->insertGetId(['user_id' => $user->user_id, 'state' => 1]);

    $paisId = DB::table('countries')->insertGetId([
        'name' => 'Colombia', 'iso_code' => 'CO',
    ]);
    $depId = DB::table('departments')->insertGetId(['name' => 'Atlántico', 'country_id' => $paisId]);
    $muniId = DB::table('municipalities')->insertGetId([
        'name' => 'Barranquilla', 'department_id' => $depId,
    ]);

    $addressId = DB::table('user_address')->insertGetId([
        'user_id' => $user->user_id,
        'address' => 'Calle 89 #68-81',
        'municipality_id' => $muniId,
        'state' => 1,
    ], 'address_id');

    $id = DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'address_id' => $addressId,
        'total' => 4500, 'subtotal' => 2500, 'domicilio' => 2000,
        'sale_date' => now(), 'state' => 1,
    ], 'orderSales_id');

    return OrdersSales::find($id);
}

/* ---------------------------------------------------------------------- */

test('la ciudad y el departamento van como texto, no como el modelo entero', function () {
    $order = pedidoConDireccionCompleta();

    $enviado = null;
    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('createIntent')
        ->once()
        ->andReturnUsing(function ($body) use (&$enviado) {
            $enviado = $body;

            return ['payload' => ['status' => 'ACTIVE']];
        });

    app(PaymentController::class)->createIntent($order, $bold);

    $direccion = $enviado['customer']['billing_address'];

    expect($direccion['city'])->toBe('Barranquilla')
        ->and($direccion['province'])->toBe('Atlántico');

    // Lo que de verdad rechazaba la pasarela: cualquier cosa que no sea texto.
    expect($direccion['city'])->toBeString()
        ->and($direccion['province'])->toBeString();
});

test('sin municipio no revienta: va nulo, que es lo que la pasarela admite', function () {
    // Es el caso de las direcciones viejas, y el que hacía parecer que todo
    // funcionaba. Tiene que seguir pasando, no convertirse en un error.
    $order = pedidoConDireccionCompleta();
    DB::table('user_address')->where('address_id', $order->address_id)
        ->update(['municipality_id' => null]);

    $enviado = null;
    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('createIntent')->once()->andReturnUsing(
        function ($body) use (&$enviado) {
            $enviado = $body;

            return ['payload' => ['status' => 'ACTIVE']];
        },
    );

    app(PaymentController::class)->createIntent($order->fresh(), $bold);

    expect($enviado['customer']['billing_address']['city'])->toBeNull()
        ->and($enviado['customer']['billing_address']['province'])->toBeNull();
});

test('las cuentas de demostración usan un dominio que la pasarela acepta', function () {
    /*
     * `.test` está reservado igual que `example.com`, pero Bold lo rechaza
     * —"value is not a valid email address"— y sin correo válido no crea la
     * orden de pago: con las cuentas de demostración era imposible probar un
     * cobro de principio a fin.
     */
    expect(Database\Seeders\DemoSeeder::DOMINIO)->toEndWith('example.com')
        ->and(Database\Seeders\DemoSeeder::DOMINIO)->not->toEndWith('.test');
});

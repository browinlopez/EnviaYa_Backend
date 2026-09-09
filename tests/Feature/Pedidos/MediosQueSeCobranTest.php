<?php

use App\Models\User;
use App\Services\PagoEnLinea;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * NO SE OFRECE UN MEDIO DE PAGO QUE NO SE SABE COBRAR.
 *
 * La tabla tiene seis medios y el sistema solo cobra TRES: efectivo en la
 * puerta, y tarjeta de crédito y QR por pasarela. Los otros tres —tarjeta
 * débito, transferencia bancaria y pago móvil (Nequi, Daviplata)— se ofrecían
 * igual en la pantalla de pago.
 *
 * Elegir uno de esos dejaba el pedido en `pending_cash`: nadie abría un cobro,
 * el comprador creía que había transferido, y el domiciliario llegaba a la
 * puerta esperando efectivo. Nadie había acordado nada, y la diferencia se
 * descubre discutiendo en el andén.
 *
 * Se filtra POR CÓDIGO y no apagando las filas: si mañana alguien vuelve a
 * encender una desde el panel, el hueco no reaparece.
 */

beforeEach(function () {
    foreach ([
        1 => 'Efectivo',
        2 => 'Tarjeta de crédito',
        3 => 'Tarjeta débito',
        4 => 'Transferencia bancaria',
        5 => 'Pago por QR',
        6 => 'Pago móvil (Nequi, Daviplata)',
    ] as $id => $nombre) {
        DB::table('payment_methods')->insertOrIgnore([
            'methods_id' => $id, 'name' => $nombre, 'state' => 1,
        ]);
    }

    \App\Models\Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
});

it('solo se ofrecen los medios que el sistema sabe cobrar', function () {
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $ofrecidos = collect($this->getJson('/v1/paymentMethods')->assertOk()->json())
        ->pluck('methods_id')
        ->sort()
        ->values()
        ->all();

    expect($ofrecidos)->toBe([1, 2, 5]);
});

it('los que no se cobran NO aparecen, aunque esten activos en la tabla', function () {
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $nombres = collect($this->getJson('/v1/paymentMethods')->json())->pluck('name');

    /*
     * Los tres del hueco. Estan `state = 1` en la tabla —no se apagaron— y aun
     * asi no se ofrecen, que es justo lo que hace que encender la fila desde el
     * panel no vuelva a abrir el agujero.
     */
    expect($nombres)->not->toContain('Tarjeta débito')
        ->and($nombres)->not->toContain('Transferencia bancaria')
        ->and($nombres)->not->toContain('Pago móvil (Nequi, Daviplata)');

    expect(DB::table('payment_methods')->where('methods_id', 3)->value('state'))->toBe(1);
});

it('la lista de lo que se cobra vive en UN solo sitio', function () {
    /*
     * `[2, 5]` estaba escrito a mano en `PagoEnLinea` y otra vez en
     * `ArmadoDelPedido`. Añadir un medio en uno y olvidarlo en el otro deja
     * pedidos esperando un cobro que nadie abrio —o al reves, cobrando algo
     * que el pedido da por pagado en efectivo—.
     */
    $armado = file_get_contents(app_path('Services/ArmadoDelPedido.php'));

    expect($armado)->toContain('PagoEnLinea::CON_PASARELA')
        ->and($armado)->not->toContain('in_array($metodoId, [2, 5])');
});

it('efectivo y pasarela juntos son lo que se ofrece', function () {
    // Que la lista compuesta no se desincronice de sus dos partes.
    expect(PagoEnLinea::metodosQueSeCobran())
        ->toBe(array_merge(PagoEnLinea::CONTRA_ENTREGA, PagoEnLinea::CON_PASARELA));
});

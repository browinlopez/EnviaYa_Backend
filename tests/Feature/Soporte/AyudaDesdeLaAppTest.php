<?php

use App\Models\Buyer\Buyer;
use App\Models\Operacion\Pqrs;
use App\Models\Order\OrdersSales;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PEDIR AYUDA DESDE LA APP.
 *
 * Antes no había a dónde escribir: el reclamo terminaba en el WhatsApp
 * personal de alguien, sin radicado y sin plazo. Ahora cae en la misma bandeja
 * que atiende el equipo.
 *
 * Lo que se fija acá es sobre todo de quién es cada caso: el nombre y el
 * correo salen de la cuenta, no de lo que se teclee, y un pedido ajeno no se
 * puede colgar del reclamo propio.
 */

beforeEach(function () {
    App\Models\Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
});

function comprador(string $correo = 'vecina@ejemplo.com'): User
{
    $user = User::factory()->create(['email' => $correo, 'rol' => 1, 'phone' => '3001234567']);
    Buyer::create(['user_id' => $user->user_id]);

    return $user;
}

function pedidoDeQuienEscribe(User $user): OrdersSales
{
    $buyer = Buyer::where('user_id', $user->user_id)->first();

    $id = DB::table('orderssales')->insertGetId([
        'total'      => 25000,
        'subtotal'   => 23000,
        'domicilio'  => 2000,
        'sale_date'  => now(),
        'state'      => 4,
        'buyer_id'   => $buyer->buyer_id,
    ], 'orderSales_id');

    return OrdersSales::find($id);
}

test('un reclamo desde la app llega a la bandeja con su radicado', function () {
    $user = comprador();

    $r = $this->actingAs($user)->postJson('/v1/ayuda', [
        'type'        => 'reclamo',
        'subject'     => 'Llegó un producto vencido',
        'description' => 'El aceite venía con fecha de julio.',
    ]);

    $r->assertCreated()->assertJsonStructure(['message', 'code']);

    $caso = Pqrs::first();

    expect($caso->channel)->toBe('app')
        ->and($caso->user_id)->toBe($user->user_id)
        // Los datos de contacto salen de la cuenta: un reclamo que dice ser de
        // otra persona no le sirve a nadie.
        ->and($caso->contact_email)->toBe('vecina@ejemplo.com')
        ->and($caso->contact_name)->toBe($user->name)
        // Un reclamo corre más que una sugerencia, y el plazo se fija al radicar.
        ->and($caso->priority)->toBe('alta')
        ->and($caso->due_at->isFuture())->toBeTrue()
        ->and($caso->state)->toBe(0);
});

test('con el pedido delante, el caso ya sabe a qué tienda mirar', function () {
    $user = comprador();
    $pedido = pedidoDeQuienEscribe($user);

    $this->actingAs($user)->postJson('/v1/ayuda', [
        'type'        => 'queja',
        'subject'     => 'El domiciliario nunca llegó',
        'description' => 'Esperé una hora.',
        'order_id'    => $pedido->orderSales_id,
    ])->assertCreated();

    expect(Pqrs::first()->order_id)->toBe($pedido->orderSales_id);
});

test('no se puede colgar el reclamo de un pedido ajeno', function () {
    $mio = comprador();
    $ajeno = comprador('otro@ejemplo.com');
    $pedido = pedidoDeQuienEscribe($ajeno);

    /*
     * Sin esta comprobación bastaría con probar números correlativos para que
     * el panel viera un reclamo señalando a una tienda y a un domiciliario que
     * no tuvieron nada que ver.
     */
    $this->actingAs($mio)->postJson('/v1/ayuda', [
        'type'        => 'reclamo',
        'subject'     => 'Reclamo del pedido de otro',
        'description' => 'A ver si cuela.',
        'order_id'    => $pedido->orderSales_id,
    ])->assertForbidden();

    expect(Pqrs::count())->toBe(0);
});

test('sin sesión no se radica nada', function () {
    $this->postJson('/v1/ayuda', [
        'type'        => 'peticion',
        'subject'     => 'Hola',
        'description' => 'Sin cuenta.',
    ])->assertUnauthorized();
});

test('el tipo y el texto se validan', function () {
    $user = comprador();

    $this->actingAs($user)->postJson('/v1/ayuda', [
        'type'        => 'felicitacion', // eso es una reseña, no un PQRS
        'subject'     => 'Todo bien',
        'description' => 'Gracias.',
    ])->assertStatus(422);

    $this->actingAs($user)->postJson('/v1/ayuda', [
        'type'    => 'queja',
        'subject' => 'Sin cuerpo',
    ])->assertStatus(422);

    expect(Pqrs::count())->toBe(0);
});

test('cada quien ve sus casos y solo los suyos', function () {
    $mio = comprador();
    $ajeno = comprador('otro@ejemplo.com');

    $this->actingAs($mio)->postJson('/v1/ayuda', [
        'type' => 'sugerencia', 'subject' => 'Mío', 'description' => 'x',
    ])->assertCreated();

    $this->actingAs($ajeno)->postJson('/v1/ayuda', [
        'type' => 'sugerencia', 'subject' => 'Ajeno', 'description' => 'y',
    ])->assertCreated();

    $r = $this->actingAs($mio)->getJson('/v1/ayuda')->assertOk();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.subject'))->toBe('Mío');
});

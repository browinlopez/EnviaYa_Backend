<?php

declare(strict_types=1);

use App\Http\Resources\Chat\ChatResource;
use App\Http\Resources\Domiciliario\ContratoResource;
use App\Http\Resources\Operacion\DepositoResource;
use App\Http\Resources\Pedido\PosicionDelDomiciliarioResource;
use App\Http\Resources\Promocion\PromocionDeLaFichaResource;
use App\Http\Resources\Promocion\PromocionResource;
use App\Models\Chat\Chat;
use App\Models\Operacion\CashDeposit;
use App\Models\Order\OrderGeolocation;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Collection as Eloquentes;

/**
 * LA NOVENA TANDA NO CAMBIA NINGUNA RESPUESTA.
 *
 * Cinco formas que estaban escritas a mano dentro de un controlador pasan a
 * su `JsonResource`. La regla de todo este trabajo es que la API siga
 * devolviendo exactamente lo mismo, así que cada caso compara la salida del
 * Resource con EL LITERAL que tenía el controlador, sobre el mismo objeto.
 *
 * QUÉ CAZA UNA COMPARACIÓN ASÍ, que es por lo que vale la pena. Al mover una
 * forma de ocho claves lo que se rompe no es la sintaxis: es una clave
 * renombrada, un `(int)` que se cayó, un `empty()` que se volvió `!`. Nada de
 * eso falla al compilar, ninguna prueba de las que había lo mira, y el efecto
 * es una pantalla que recibe `null` donde esperaba un número.
 *
 * Y UNA DE LAS CINCO ES LA QUE IMPORTA MÁS. `PromocionDeLaFichaResource` nace
 * de DOS copias de la misma forma, en el mismo archivo, a setenta líneas una
 * de otra. Acá se comprueba lo que hace que esa unión sea correcta: que las
 * dos copias daban lo mismo —si no, unirlas habría cambiado una de las dos— y
 * que la clase nueva NO es `PromocionResource`, que es la del panel del
 * tendero y lleva veintitantas claves.
 */
it('la promocion de la ficha da la misma forma que las dos copias que habia', function () {
    $p = new Promotion;
    $p->setRawAttributes([
        'promotion_id' => '12',
        'busines_id' => 3,
        'description' => 'Compra 2 y el tercero va gratis',
        'tipo' => Promotion::NXM,
        'lleva' => 3,
        'paga' => 2,
        'end_date' => now()->addDays(2)->toDateTimeString(),
        'start_date' => now()->subDay()->toDateTimeString(),
        'state' => 1,
        'usos' => 0,
        'recipients_count' => 0,
    ], true);

    /* El literal que estaba en `ConsultaDeNegociosController`, las dos veces.
       Las dos copias eran idénticas hasta el molde, así que basta uno. */
    $comoEstaba = [
        'promotion_id' => (int) $p->promotion_id,
        'texto' => (string) $p->description,
        'regla' => $p->reglaEnPalabras(),
        'hasta_texto' => $p->hastaEnPalabras(),
    ];

    /* `toEqual` y no `toBe`: hay fechas de por medio y `toBe` compara la
       IDENTIDAD de los objetos, asi que dos Carbon con la misma fecha no son
       el mismo. Lo que se comprueba es el valor. El orden de las claves se
       comprueba aparte, abajo, porque `toEqual` no lo mira. */
    expect((new PromocionDeLaFichaResource($p))->resolve())->toEqual($comoEstaba);
    expect(array_keys((new PromocionDeLaFichaResource($p))->resolve()))
        ->toBe(array_keys($comoEstaba));

    /* Y los moldes no son decoración: `promotion_id` entra como la cadena
       '12' —así lo devuelve el driver— y tiene que salir entero. */
    expect((new PromocionDeLaFichaResource($p))->resolve()['promotion_id'])->toBeInt();
});

it('la del comprador NO es la del panel, que es el motivo de que haya dos', function () {
    $p = new Promotion;
    $p->setRawAttributes([
        'promotion_id' => 12, 'busines_id' => 3, 'description' => 'x',
        'tipo' => Promotion::SIN_DESCUENTO, 'state' => 1, 'usos' => 4,
        'max_usos' => 50, 'recipients_count' => 80,
    ], true);

    $comprador = array_keys((new PromocionDeLaFichaResource($p))->resolve());
    $panel = array_keys((new PromocionResource($p))->resolve());

    /* Cuatro claves contra veintitantas. Si alguien «simplificara» usando la
       del panel en la ficha, el comprador recibiría cuántos envíos le quedan
       a la tienda y cuántos destinatarios tiene. */
    expect($comprador)->toHaveCount(4);
    expect(count($panel))->toBeGreaterThan(count($comprador));

    foreach (['usos', 'max_usos', 'destinatarios', 'busines_id'] as $delPanel) {
        expect($comprador)->not->toContain($delPanel);
    }
});

it('la ultima posicion da la misma forma que el array que habia', function () {
    $g = new OrderGeolocation;
    $g->setRawAttributes([
        'order_geolocation_id' => 1,
        'domiciliary_id' => 7,
        'orderSales_id' => 3,
        'latitude' => '4.6097100',
        'longitude' => '-74.0817500',
        'state' => 1,
        'created_at' => '2026-11-12 10:00:00',
    ], true);

    $comoEstaba = [
        'latitude' => $g->latitude,
        'longitude' => $g->longitude,
        'orderSales_id' => $g->orderSales_id,
        'state' => $g->state,
        'created_at' => $g->created_at,
    ];

    expect((new PosicionDelDomiciliarioResource($g))->resolve())->toEqual($comoEstaba);
    expect(array_keys((new PosicionDelDomiciliarioResource($g))->resolve()))
        ->toBe(array_keys($comoEstaba));
});

it('el chat da la misma forma que formatChatResponse', function () {
    $chat = new Chat;
    $chat->setRawAttributes([
        'chat_id' => 5,
        'type' => 'private',
        'created_at' => '2026-11-12 10:00:00',
    ], true);

    /* Sin filas: lo que se comprueba es la envoltura —los nombres de las tres
       claves propias y que las dos listas vayan resueltas—, no los hijos, que
       ya tienen su prueba desde la quinta tanda. */
    $chat->setRelation('participants', new Eloquentes);
    $chat->setRelation('messages', new Eloquentes);

    $publicado = (new ChatResource($chat))->resolve();

    expect(array_keys($publicado))
        ->toBe(['chat_id', 'type', 'created_at', 'participants', 'messages']);

    /* `->resolve()` y no el Resource sin resolver: devolverlo crudo mete la
       envoltura `data` de Laravel y eso cambiaría la respuesta. */
    expect($publicado['participants'])->toBeArray();
    expect($publicado['messages'])->toBeArray();
});

it('el acuerdo da la misma forma, y acepta la fila cruda que le llega', function () {
    /* `suyo()` devuelve el stdClass de un `DB::table()->leftJoin()`, no un
       modelo. Puesto `Domiciliary` en el constructor, los seis casos de
       `ContratoDesdeLaAppTest` daban 500. */
    $d = (object) [
        'domiciliary_id' => 5,
        'user_id' => 9,
        'document' => '1020304050',
        'contract_signed_at' => null,
        'contract_city' => 'Bogotá',
        'contract_media_id' => null,
        'name' => 'Ana',
    ];

    $texto = [['tipo' => 'titulo', 'texto' => 'Acuerdo de colaboración']];

    expect((new ContratoResource($d, null, $texto))->resolve())->toBe([
        'firmado' => false,
        'firmado_el' => null,
        'ciudad' => 'Bogotá',
        'documento' => '1020304050',
        'nombre' => 'Ana',
        'falta_documento' => false,
        'url' => null,
        'texto' => $texto,
    ]);

    /* Y la mitad que de verdad decide algo: sin cédula, la pantalla la pide
       antes de firmar en vez de dejar que el envío reviente al final. */
    $sinCedula = clone $d;
    $sinCedula->document = null;

    expect((new ContratoResource($sinCedula, null, $texto))->resolve()['falta_documento'])
        ->toBeTrue();
});

it('la consignacion no publica una columna que la lista no tiene', function () {
    $dep = new CashDeposit;
    $dep->setRawAttributes([
        'id' => 3, 'domiciliary_id' => 7, 'canal' => 'banco',
        'amount' => '150000.00', 'state' => 1, 'reference' => 'ABC-1',
        'columna_recien_migrada' => 'no deberia salir',
    ], true);

    $publicado = (new DepositoResource($dep))->resolve();

    expect($publicado)->not->toHaveKey('columna_recien_migrada');
    expect($publicado)->toHaveKey('amount', '150000.00');

    /* CONTROL: el crudo SÍ la saca, que es lo que devolvían los dos sitios de
       `EfectivoController` antes de esta tanda. Sin esta afirmación, un
       Resource que devolviera un array vacío pasaría la de arriba. */
    expect($dep->toArray())->toHaveKey('columna_recien_migrada');
});

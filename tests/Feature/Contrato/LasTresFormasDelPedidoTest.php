<?php

declare(strict_types=1);

use App\Http\Resources\Pedido\PedidoDelCompradorResource;
use App\Http\Resources\Pedido\PedidoResource;
use App\Http\Resources\Pedido\RenglonDelPedidoResource;
use App\Models\Business;
use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Collection as Eloquentes;

/**
 * EL PEDIDO SE PUBLICA EN TRES FORMAS, Y SE DIFERENCIAN EN TRES CLAVES.
 *
 * El pedido es la entidad más central del sistema y cada pantalla recibía una
 * forma propia, armada a mano:
 *
 *   PedidoResource                 23  lo que devuelven cancelar y pesar, en
 *                                      el panel del tendero
 *   PedidoDeLaAppResource          30  la lista del domiciliario
 *   PedidoDelCompradorResource     31  el historial del comprador, que era lo
 *                                      único que seguía dentro de un método
 *
 * Medidas clave por clave, las tres son casi la misma:
 *
 *   · `PedidoResource` es un SUBCONJUNTO ESTRICTO del del comprador: no tiene
 *     ni una clave que el otro no tenga;
 *   · el del comprador y el del domiciliario comparten VEINTINUEVE, y la
 *     diferencia total son tres claves: `buyer` solo en el del domiciliario,
 *     `has_review` y `weighing_state` solo en el del comprador.
 *
 * Tres formas que difieren en tres claves no son un diseño: son una deriva.
 * Unificarlas cambia lo que reciben tres pantallas ya publicadas, así que lo
 * que hace esta prueba es FIJAR la deriva: si alguien añade una clave a una
 * sola, se pone roja y hay que decidirlo a propósito.
 *
 * Y EL RENGLÓN YA NO ESTÁ DOS VECES. La forma de cada línea de pedido estaba
 * escrita dos veces, idéntica línea por línea, y sus cinco últimas claves son
 * las de la venta por peso —lo más nuevo del sistema y lo que más va a
 * cambiar—. Si alguien añadía un campo de peso a una copia y no a la otra, el
 * historial del comprador y la lista del domiciliario decían cosas distintas
 * DEL MISMO PEDIDO. Ahora las dos pasan por `RenglonDelPedidoResource`.
 */
function unPedidoConSuNegocio(): OrdersSales
{
    $b = new Business;
    $b->setRawAttributes([
        'busines_id' => 3,
        'name' => 'Tienda Ana',
        'address' => 'Calle 2',
        'latitude' => '4.6097100',
        'longitude' => '-74.0817500',
        'phone' => '3009999999',
        'city' => 'Bogotá',
        'state' => 1,
        'logo' => 'logo.png',
        'qualification' => '4.80',
    ], true);

    $o = new OrdersSales;
    $o->setRawAttributes([
        'orderSales_id' => 11,
        'buyer_id' => 4,
        'busines_id' => 3,
        'total' => '28000.00',
        'subtotal' => '26000.00',
        'domicilio' => '2000.00',
        'domiciliary_fee' => '1500.00',
        'cash_tendered' => '50000.00',
        'weighing_state' => 0,
        'methods_id' => 1,
        'payment_state' => 1,
        'sale_date' => '2026-11-12 10:00:00',
        'is_scheduled' => 0,
        'pickup' => 0,
        'has_review' => 0,
        'state' => 4,
    ], true);

    $o->setRelation('business', $b);
    $o->setRelation('address', null);
    $o->setRelation('domiciliary', null);
    $o->setRelation('details', new Eloquentes);
    $o->setRelation('promotions', new Eloquentes);
    $o->setRelation('payments', new Eloquentes);

    return $o;
}

it('la forma del tendero es un subconjunto estricto de la del comprador', function () {
    $pedido = unPedidoConSuNegocio();

    $tendero = array_keys((new PedidoResource($pedido))->resolve());
    $comprador = array_keys((new PedidoDelCompradorResource($pedido))->resolve());

    /* Ni una clave suya que no esté en la otra. Si algún día aparece, es que
       el panel empezó a publicar algo que el historial no tiene, y eso hay
       que mirarlo: las dos salen del mismo pedido. */
    expect(array_values(array_diff($tendero, $comprador)))->toBe([]);

    /* Y la del comprador tiene ocho más, que es lo que la hace la mayor. */
    expect(count($comprador) - count($tendero))->toBe(8);
});

it('el comprador recibe lo que gana el domiciliario por su pedido', function () {
    $publicado = (new PedidoDelCompradorResource(unPedidoConSuNegocio()))->resolve();

    /* MEDIDO, NO ARREGLADO. El comprador paga `domicilio`; `domiciliary_fee`
       es lo que de ahí se lleva el domiciliario, así que mandarle las dos le
       dice el margen de cada entrega. Hay trabajo hecho para que el
       domiciliario no vea sus propias ganancias en su app; el comprador sí
       las ve.

       Quitarlo cambia lo que recibe una app publicada, así que es una
       decisión de producto. Si se toma, esta prueba se pone roja y hay que
       borrarla: será la señal de que se decidió. */
    expect($publicado)->toHaveKey('domiciliary_fee', '1500.00');
    expect($publicado)->toHaveKey('domicilio', '2000.00');
});

it('el renglon publica trece claves, y las cinco ultimas son las del peso', function () {
    $p = new Product;
    $p->setRawAttributes([
        'products_id' => 7,
        'name' => 'Queso',
        'description' => 'Campesino',
        'image' => 'queso.png',
    ], true);
    $p->setRelation('category', null);

    $d = new OrdersSalesDetail;
    $d->setRawAttributes([
        'orderDet_id' => 21,
        'amount' => 1,
        'unit_price' => '18000.00',
        'sale_unit' => 'kg',
        'pedido_por' => 'valor',
        'qty_requested' => '0.500',
        'qty_final' => '0.540',
        'line_total' => '9000.00',
    ], true);
    $d->setRelation('product', $p);

    expect(array_keys((new RenglonDelPedidoResource($d))->resolve()))->toBe([
        'orderDet_id', 'product_id', 'name', 'description', 'category', 'image',
        'amount', 'unit_price',
        // Las del peso. Están al final y en este orden en las dos pantallas
        // porque ahora son una sola lista.
        'sale_unit', 'pedido_por', 'qty_requested', 'qty_final', 'line_total',
    ]);

    /* `qty_requested` y `qty_final` salen tal cual, sin juzgar el desvío: eso
       es de `PesajeDelPedidoController`, que tiene el tope configurado. Un
       Resource que lo juzgara estaría poniendo reglas de negocio en la
       frontera. */
    expect((new RenglonDelPedidoResource($d))->resolve()['qty_final'])->toBe('0.540');
});

it('la clave city publica null porque esa columna no existe', function () {
    $publicado = (new PedidoDelCompradorResource(unPedidoConSuNegocio()))->resolve();

    /* `business.city` NO EXISTE como columna de `business`. La tabla tiene
       `municipality_id`, y la ciudad de verdad sale de ahí. Esta clave ha
       estado publicando `null` desde que se escribió, en CINCO respuestas
       distintas, y además `'city'` estaba en el `$fillable` del modelo: si
       algo hubiera intentado escribirla, el INSERT habría reventado.

       Lo destapó PHPStan al tipar el recurso. Mientras la relación `business`
       no estaba declarada con su tipo, no podía comprobar el campo: el aviso
       no existía, no es que estuviera silenciado.

       SE CONSERVA LA CLAVE, con `null` explícito en el código: la salida es la
       misma que hoy y quitarla obligaría a la app a defenderse de un
       `undefined` donde hoy recibe un nulo. Esta prueba está para que nadie
       lea `'city'` en la respuesta y crea que trae la ciudad. */
    expect($publicado['business'])->toHaveKey('city');
    expect($publicado['business']['city'])->toBeNull();

    /* La ciudad de verdad, cuando haga falta, sale de acá. En este pedido la
       dirección es nula —es un pedido de recogida— así que lo que se fija es
       dónde está el dato, no su valor. */
    expect(array_keys($publicado))->toContain('delivery_address');
});

it('CONTROL: el negocio de la prueba SI trae lo que de verdad existe', function () {
    /* Sin esto, el `toBeNull()` de arriba pasaría también si el bloque
       `business` estuviera entero vacío o mal construido: «city es null» es
       verdad cuando nada tiene valor. */
    $publicado = (new PedidoDelCompradorResource(unPedidoConSuNegocio()))->resolve();

    expect($publicado['business']['name'])->toBe('Tienda Ana');
    expect($publicado['business']['phone'])->toBe('3009999999');
});

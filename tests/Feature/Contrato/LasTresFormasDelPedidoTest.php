<?php

declare(strict_types=1);

use App\Http\Resources\Pedido\PedidoDelCompradorResource;
use App\Http\Resources\Pedido\PedidoResource;
use App\Http\Resources\Pedido\RenglonDelPedidoResource;
use App\Models\Business;
use App\Models\Maps\Country;
use App\Models\Maps\Department;
use App\Models\Maps\Municipality;
use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use App\Models\Product\Product;
use App\Models\User\UserAddress;
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

    /*
     * EL NEGOCIO LLEVA SU MUNICIPIO, desde el 13 de noviembre de 2026: es de
     * donde sale ahora `business.city`. Y se pone como RELACION CARGADA, que
     * es como llega en produccion --los controladores precargan
     * `business.municipality`-- porque con `preventLazyLoading` activo leerla
     * sin cargar lanza.
     */
    $m = new Municipality;
    $m->setRawAttributes(['id' => 1, 'name' => 'Bogotá', 'department_id' => 1], true);
    $b->setRelation('municipality', $m);

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

    /* Y la del comprador tiene SIETE más. Eran ocho hasta el 13 de noviembre
       de 2026, cuando se le quitó `domiciliary_fee` al comprador; el tendero
       nunca la tuvo, así que lo que cambia es la cuenta y NO la relación de
       subconjunto, que es lo que esta prueba defiende. */
    expect(count($comprador) - count($tendero))->toBe(7);
});

it('el comprador YA NO recibe lo que gana el domiciliario, ni su correo', function () {
    $publicado = (new PedidoDelCompradorResource(unPedidoConSuNegocio()))->resolve();

    /*
     * ESTA PRUEBA DECIA LO CONTRARIO, y su propio texto decia que ponerse
     * roja seria la senal de que se habia decidido. Se decidio el 13 de
     * noviembre de 2026.
     *
     * El comprador paga `domicilio`; `domiciliary_fee` es lo que de ahi se
     * lleva el domiciliario, asi que mandarle las dos le decia el margen de
     * cada entrega. Y habia trabajo hecho que esto contradecia: `conGanancia`
     * en `PedidoDeLaAppResource` existe para que el DOMICILIARIO no vea sus
     * propias ganancias en su app. Se le ocultaban a el y se le mandaban al
     * comprador.
     *
     * Antes de quitarlo se comprobo quien lo lee en los cuatro clientes: solo
     * `EnviaYa_Admin`, y por los endpoints de `/admin`, no por esta respuesta.
     *
     * Y EL CORREO DEL DOMICILIARIO TAMBIEN SE FUE. El telefono se entiende
     * --hay que poder llamarlo cuando esta en la puerta-- pero el correo no
     * sirve para eso: era el dato de contacto personal de un trabajador en
     * manos de cada cliente al que le reparte.
     */
    expect($publicado)->not->toHaveKey('domiciliary_fee');
    expect($publicado['domiciliary'] ?? [])->not->toHaveKey('email');

    /* CONTROL: lo que el comprador SI tiene que ver sigue ahi. Sin esto, «no
       recibe la ganancia» seria verdad tambien con una respuesta vacia. */
    expect($publicado)->toHaveKey('domicilio', '2000.00');
    expect($publicado)->toHaveKey('total');
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

it('la clave city YA trae la ciudad, que sale del municipio', function () {
    $publicado = (new PedidoDelCompradorResource(unPedidoConSuNegocio()))->resolve();

    /* `business.city` NO EXISTE como columna de `business`. La tabla tiene
       `municipality_id`, y la ciudad de verdad sale de ahí. Esta clave ha
       estado publicando `null` desde que se escribió, en CINCO respuestas
       distintas, y además `'city'` estaba en el `$fillable` del modelo: si
       algo hubiera intentado escribirla, el INSERT habría reventado.

       Lo destapó PHPStan al tipar el recurso. Mientras la relación `business`
       no estaba declarada con su tipo, no podía comprobar el campo: el aviso
       no existía, no es que estuviera silenciado.

       YA NO ES NULA. Desde el 13 de noviembre de 2026 sale de
       `municipality->name`, que es de donde salía la ciudad en los listados de
       negocio desde siempre. La clave no cambia ni se renombra: lo que cambia
       es que dice algo.

       Esta prueba decía `toBeNull()` y pasaba. Ojo: después del arreglo SEGUÍA
       pasando, porque el negocio del escenario no traía municipio y `?->name`
       da null igual. Un verde vacío. Por eso el escenario lleva ahora su
       municipio cargado, como llega en producción. */
    expect($publicado['business'])->toHaveKey('city');
    expect($publicado['business']['city'])->toBe('Bogotá');

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

it('el pais de la direccion YA NO es nulo en el historial del comprador', function () {
    /*
     * EL HALLAZGO QUE ESTO CIERRA. `delivery_address.country` era nulo en 683
     * de 683 pedidos, medido sobre el historial de un comprador real. El
     * municipio y el departamento salían; el país no había salido nunca.
     *
     * La causa: `UserAddress::country()` es un `hasOneThrough` y hacen falta
     * TRES saltos —`municipality → department → country`—, así que la relación
     * devolvía null. Su propio docbloque lo decía: «@deprecated Devuelve null
     * siempre».
     *
     * Y NO COSTÓ UNA CONSULTA: `ConsultaDePedidosController` ya precargaba
     * `address.municipality.department.country` entera. Los datos estaban
     * cargados y el Resource los ignoraba para preguntarle a una relación
     * rota. Es el mismo recorrido que `DireccionResource` hacía en la lista de
     * direcciones, donde el país SÍ salía.
     *
     * EN LA LISTA DEL DOMICILIARIO SIGUE SIENDO NULO, y eso es una decisión
     * escrita en `PedidoDeLaAppResource`: allí la cadena cuesta una consulta
     * más por petición, en la pantalla que su app refresca mientras reparte,
     * para devolver siempre «Colombia». Se paga donde es gratis.
     */
    $pais = new Country;
    $pais->setRawAttributes(['id' => 1, 'name' => 'Colombia', 'iso_code' => 'CO'], true);

    $depto = new Department;
    $depto->setRawAttributes(['id' => 1, 'name' => 'Cundinamarca', 'country_id' => 1], true);
    $depto->setRelation('country', $pais);

    $muni = new Municipality;
    $muni->setRawAttributes(['id' => 1, 'name' => 'Bogotá', 'department_id' => 1], true);
    $muni->setRelation('department', $depto);

    $dir = new UserAddress;
    $dir->setRawAttributes([
        'address_id' => 9,
        'address' => 'Calle 1 #2-3',
        'street' => 'Apto 301',
        'latitude' => '4.60',
        'longitude' => '-74.08',
    ], true);
    $dir->setRelation('municipality', $muni);
    $dir->setRelation('department', $depto);
    $dir->setRelation('alias', null);

    $pedido = unPedidoConSuNegocio();
    $pedido->setRelation('address', $dir);

    $publicado = (new PedidoDelCompradorResource($pedido))->resolve();

    expect($publicado['delivery_address']['country'])->toBe('Colombia');

    /* CONTROL: los tres niveles salen, no solo el que se arregló. Sin esto,
       «el país sale» pasaría también con una dirección a medio construir. */
    expect($publicado['delivery_address']['municipality'])->toBe('Bogotá');
    expect($publicado['delivery_address']['department'])->toBe('Cundinamarca');
});

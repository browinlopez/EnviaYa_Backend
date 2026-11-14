<?php

declare(strict_types=1);

use App\Models\Domiciliary;
use App\Models\Order\OrdersSales;
use App\Models\Product\Product;
use App\Models\User;
use App\Policies\DomiciliaryPolicy;
use App\Policies\OrdersSalesPolicy;
use Illuminate\Support\Facades\Gate;

/**
 * LAS POLICIES EXISTEN, ESTÁN REGISTRADAS Y DICEN NO.
 *
 * §08 pide Policy o Gate por recurso **y prueba de denegación**. Hasta el 14
 * de noviembre de 2026 las reglas vivían en cuatro métodos privados de cuatro
 * controladores; ahora están en `app/Policies/` y esto vigila las tres cosas
 * que pueden romperse sin que nada chille.
 *
 * 1 · QUE RESUELVAN PARA SU MODELO. Esta prueba decía que comprobaba el
 *     registro a mano, y NO ES ESO lo que comprueba: se intentó romper
 *     comentando el `Gate::policy` y siguió en verde, porque el buscador de
 *     Laravel sube por el espacio de nombres y encuentra
 *     `App\Policies\OrdersSalesPolicy` igualmente.
 *
 *     Lo que sí comprueba, y es la propiedad que importa, es que la Policy
 *     RESUELVE para su modelo —por registro o por descubrimiento, da igual
 *     cuál— porque **una Policy que no se encuentra no lanza: simplemente no
 *     se aplica.** Si alguien la renombra, la mueve a un espacio de nombres
 *     que el buscador no visita o le cambia el modelo, esto lo dice.
 *
 * 2 · QUE LAS PUERTAS CON NOMBRE EXISTAN. Dos reglas no reciben el modelo
 *     —una recibe un `domiciliary_id` y otra el usuario objetivo— así que van
 *     por `Gate::define`. Si alguien borra una de esas definiciones,
 *     `Gate::allows('nombre-que-no-existe')` devuelve **false**: falla
 *     cerrado, que es lo correcto, pero deja la pantalla muerta sin decir por
 *     qué. Mejor saberlo acá.
 *
 * 3 · QUE EL MOTIVO DEL RECHAZO SOBREVIVA. `moverA` no devuelve sí o no:
 *     devuelve uno de tres mensajes, y la app los enseña. Una Policy normal
 *     devolvería un booleano y los tres se convertirían en un «no tienes
 *     permiso» genérico. Las tres cadenas están escritas acá a propósito: si
 *     alguien las cambia, se entera de que son contrato con la app.
 */
it('las dos policies resuelven para su modelo', function () {
    expect(Gate::getPolicyFor(Domiciliary::class))->toBeInstanceOf(DomiciliaryPolicy::class);
    expect(Gate::getPolicyFor(OrdersSales::class))->toBeInstanceOf(OrdersSalesPolicy::class);

    /* CONTROL: que `getPolicyFor` no devuelva algo para cualquier cosa. Sin
       esto, lo de arriba pasaría también con un resolvedor que inventara
       policies, y entonces no probaría que ESTAS están puestas. */
    expect(Gate::getPolicyFor(Product::class))->toBeNull();
});

it('las dos puertas con nombre estan definidas', function () {
    expect(Gate::has('ver-ubicacion-del-domiciliario'))->toBeTrue();
    expect(Gate::has('ver-y-editar-domiciliario'))->toBeTrue();

    /* La otra mitad: que `has()` distinga de verdad. */
    expect(Gate::has('puerta-que-nadie-definio'))->toBeFalse();
});

it('los tres motivos del rechazo al mover un pedido se conservan', function () {
    /*
     * Son contrato con la app: los pinta tal cual. Se comprueban contra la
     * Policy directamente y no por HTTP porque lo que se fija es el TEXTO, y
     * las pruebas de la transición por HTTP ya cubren quién puede.
     */
    $politica = new OrdersSalesPolicy;

    $pedido = new OrdersSales;
    $pedido->setRawAttributes([
        'orderSales_id' => 11,
        'busines_id' => 3,
        'domiciliary_id' => null,
        'state' => 1,
    ], true);
    $pedido->setRelation('domiciliary', null);

    $nadie = new User;
    $nadie->setRawAttributes(['user_id' => 999, 'rol' => 1], true);

    foreach ([
        2 => 'Solo la tienda del pedido puede aceptarlo.',
        3 => 'Solo la tienda o el domiciliario que lo toma pueden despacharlo.',
        4 => 'Solo el domiciliario asignado puede marcar la entrega.',
        9 => 'Transición no reconocida.',
    ] as $destino => $esperado) {
        $veredicto = $politica->moverA($nadie, $pedido, $destino, null);

        expect($veredicto->denied())->toBeTrue();
        expect($veredicto->message())->toBe($esperado);
    }
});

it('CONTROL: la policy tambien sabe decir SI', function () {
    /*
     * Sin esto, lo de arriba pasaría con una Policy que negara siempre —que es
     * el fallo más fácil de cometer al mudar reglas— y la operación entera
     * quedaría muerta con los mensajes correctos.
     *
     * El camino que se prueba es el del domiciliario asignado marcando la
     * entrega: no necesita base de datos, porque la regla compara el
     * `user_id` del domiciliario del pedido con el de quien pide.
     */
    $politica = new OrdersSalesPolicy;

    $repartidor = new User;
    $repartidor->setRawAttributes(['user_id' => 77, 'rol' => 3], true);

    $domiciliario = new Domiciliary;
    $domiciliario->setRawAttributes(['domiciliary_id' => 5, 'user_id' => 77], true);

    $pedido = new OrdersSales;
    $pedido->setRawAttributes([
        'orderSales_id' => 11,
        'busines_id' => 3,
        'domiciliary_id' => 5,
        'state' => 3,
    ], true);
    $pedido->setRelation('domiciliary', $domiciliario);

    expect($politica->moverA($repartidor, $pedido, 4, null)->allowed())->toBeTrue();
});

<?php

/**
 * ==========================================================================
 * UN SANCOCHO NO SALE EN LOS MISMOS VEINTE MINUTOS QUE UNA GASEOSA.
 *
 * El plazo que se le promete a quien compra salía de dos números: una base
 * de plataforma --«lo que cuesta preparar y salir»-- y los minutos por
 * kilómetro. El mismo para la tienda de la esquina que para un restaurante.
 *
 * Así que a un plato que tarda treinta y cinco minutos en salir de la cocina
 * se le prometían los veinte de todo el mundo, y el pedido entraba como
 * «tarde» en el informe sin que el cocinero hubiera hecho nada mal.
 *
 * Los platos ya tenían `minutos_preparacion` --se añadió al repartir los
 * campos por tipo-- pero NO LO LEÍA NADIE: el campo se guardaba y la promesa
 * seguía siendo una adivinanza. Esto es lo que lo conecta.
 * ========================================================================== */

use App\Models\Order\OrdersSales;
use App\Services\Ajustes;
use App\Services\PlazoPorDistancia;
use Illuminate\Support\Facades\DB;

/** El plazo, con la cocina tardando lo que se diga. */
function plazoCon(?float $km, int $cocina = 0): int
{
    return app(PlazoPorDistancia::class)->minutosPara($km, $cocina);
}

beforeEach(function () {
    /* Propios y no los de la base: los ajustes viven en la tabla y otras
       pruebas los mueven. Mismo patron que `PlazoDeEntregaTest`. */
    Ajustes::guardar([
        'operacion.tiempo_entrega_min' => 20,
        'operacion.minutos_por_km' => 4,
        'operacion.tiempo_entrega_max' => 90,
    ], null);
});
/* ---------------------------------------------------------------------- */

it('una cocina lenta levanta el plazo por encima de la base', function () {
    /*
     * ÉSTA ES LA PRUEBA QUE IMPORTA. Sin cocina, la base manda; con un
     * sancocho de 35 minutos, el plazo sube aunque la casa esté al lado.
     */
    $sinCocina = plazoCon(0.5);
    $conSancocho = plazoCon(0.5, 35);

    expect($conSancocho)->toBeGreaterThan($sinCocina);
    expect($conSancocho)->toBeGreaterThanOrEqual(35);
});

it('la cocina NO se suma a la base: se queda con la mayor', function () {
    /*
     * `max` y no `base + preparacion`, porque la base YA incluye preparar
     * --su ajuste se llama «lo que cuesta preparar y salir»--. Sumarlas
     * contaría la preparación dos veces y prometería casi una hora por un
     * almuerzo que sale en treinta.
     */
    expect(plazoCon(null, 30))->toBe(30);
    expect(plazoCon(null, 30))->not->toBe(50);
});

it('una cocina rápida NO baja el plazo por debajo de la base', function () {
    /*
     * Una gaseosa tarda cero en servirse, pero salir de la tienda y montarse
     * en la moto cuesta igual que siempre. La base es un suelo, no un
     * promedio.
     */
    expect(plazoCon(null, 0))->toBe(20);
    expect(plazoCon(null, 3))->toBe(20);
});

it('la cocina y la distancia se SUMAN entre ellas', function () {
    /*
     * Lo que no se suma es cocina con base. Cocina con trayecto sí: el
     * domiciliario no empieza a andar hasta que el plato está.
     */
    $cerca = plazoCon(1.0, 40);
    $lejos = plazoCon(5.0, 40);

    expect($lejos)->toBeGreaterThan($cerca);
    expect($cerca)->toBeGreaterThanOrEqual(40);
});

it('el tope no puede prometer menos de lo que tarda la cocina', function () {
    /*
     * El tope existe para que una dirección en el borde de la cobertura no
     * prometa hora y media. Pero si la cocina sola ya tarda más que el tope,
     * recortar ahí prometería algo que el negocio no puede cumplir nunca.
     */
    Ajustes::guardar(['operacion.tiempo_entrega_max' => 30], null);

    expect(plazoCon(8.0, 60))->toBeGreaterThanOrEqual(60);
});

it('CONTROL: sin platos que lo declaren, el plazo es el de siempre', function () {
    /*
     * Una tienda y una droguería no tienen `minutos_preparacion` en ningún
     * producto, así que su cocina vale cero y el plazo sale exactamente como
     * antes de este cambio. Si esta prueba se pone roja, se le cambió el
     * plazo a todos los negocios que no son restaurantes.
     */
    expect(plazoCon(null))->toBe(20);
    expect(plazoCon(2.0))->toBe(plazoCon(2.0, 0));
    expect(plazoCon(5.0))->toBe(plazoCon(5.0, 0));
});

it('el pedido sabe cuánto tarda su cocina, y es el MÁXIMO de sus platos', function () {
    /*
     * Una cocina no hace los platos en fila, los hace a la vez: el pedido
     * sale cuando está listo el más lento. Sumarlos le prometería dos horas a
     * una mesa de seis y marcaría tarde todo lo que entregara bien.
     */
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Restaurante'], 'id');

    $negocio = DB::table('business')->insertGetId([
        'name' => 'El Corrientazo', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $pedido = DB::table('orderssales')->insertGetId([
        'busines_id' => $negocio,
        'subtotal' => 0, 'domicilio' => 0, 'discount' => 0,
        'domiciliary_fee' => 0, 'platform_fee' => 0, 'delivery_subsidy' => 0,
        'cash_due' => 0, 'weighing_state' => 0, 'currency' => 'COP',
        'state' => 1, 'has_review' => 0, 'payment_state' => 'pending',
        'pickup' => 0, 'is_scheduled' => 0, 'stock_descontado' => 0,
    ], 'orderSales_id');

    foreach ([['Gaseosa', 0], ['Arroz con pollo', 18], ['Sancocho', 35]] as [$nombre, $minutos]) {
        $producto = DB::table('products')->insertGetId([
            'name' => $nombre, 'state' => 1,
        ], 'products_id');

        DB::table('restaurant_products')->insert([
            'products_id' => $producto,
            'minutos_preparacion' => $minutos,
            'is_vegan' => false,
            'is_gluten_free' => false,
            'es_vegetariano' => false,
        ]);

        DB::table('orderssales_detail')->insert([
            'orderSales_id' => $pedido,
            'product_id' => $producto,
            'amount' => 1,
            'unit_price' => 1000,
            'sale_unit' => 'unidad',
        ]);
    }

    $modelo = OrdersSales::find($pedido);

    expect($modelo->minutosDeCocina())->toBe(35);

    /* Y no la suma, que serían 53. */
    expect($modelo->minutosDeCocina())->not->toBe(53);
});

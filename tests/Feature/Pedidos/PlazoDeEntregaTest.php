<?php

use App\Models\Order\OrdersSales;
use App\Services\Ajustes;
use Illuminate\Support\Facades\DB;

/**
 * Plazo de entrega prometido y su cumplimiento.
 *
 * El tiempo que la app mostraba —"15min" en cada negocio, "~20 min" en el
 * seguimiento— eran constantes escritas en el código: el campo que leía no
 * existía en el servidor. Ahora es un compromiso de la operación, se ajusta en
 * el panel, se congela en el pedido al despacharlo y contra él se mide cada
 * entrega.
 *
 * Lo que se fija acá es sobre todo la regla de congelar: sin ella, subir el
 * estándar convertiría en "a tiempo" entregas pasadas que llegaron tarde.
 */

function pedidoConPlazo(int $prometidos, int $tardo): OrdersSales
{
    $despacho = now()->subHours(2);

    $id = DB::table('orderssales')->insertGetId([
        'total'            => 12000,
        'subtotal'         => 10000,
        'domicilio'        => 2000,
        'sale_date'        => $despacho->copy()->subMinutes(10),
        'dispatched_at'    => $despacho,
        'delivery_date'    => $despacho->copy()->addMinutes($tardo),
        'promised_minutes' => $prometidos,
        'state'            => 4,
    ], 'orderSales_id');

    return OrdersSales::find($id);
}

/* ---------------------------------------------------------------------- */

test('el plazo prometido llega a la app en la configuración', function () {
    $this->getJson('/v1/app/config')
        ->assertOk()
        ->assertJsonPath('operation.delivery_time_minutes', 20)
        // Las dos piezas del cálculo, para estimarlo sin preguntar.
        ->assertJsonPath('operation.delivery_time_per_km', 4)
        ->assertJsonPath('operation.delivery_time_max', 60);
});

/* ------------------------------ POR DISTANCIA ------------------------- */

test('cuanto más lejos, más minutos, redondeando de cinco en cinco', function () {
    $plazos = app(App\Services\PlazoPorDistancia::class);

    // Base 20 + 4 por km: 0,8 km son 23,2 → 25; 3,2 km son 32,8 → 35.
    expect($plazos->minutosPara(0.8))->toBe(25)
        ->and($plazos->minutosPara(3.2))->toBe(35);
});

test('el tope evita prometer hora y media en el borde de la cobertura', function () {
    $plazos = app(App\Services\PlazoPorDistancia::class);

    // 12,5 km serían 70 minutos; el máximo los deja en 60.
    expect($plazos->minutosPara(12.5))->toBe(60);
});

test('sin distancia se promete la base, como antes', function () {
    $plazos = app(App\Services\PlazoPorDistancia::class);

    // Una tienda sin coordenadas o alguien que todavía no eligió dirección.
    expect($plazos->minutosPara(null))->toBe(20)
        ->and($plazos->minutosPara(0))->toBe(20);
});

test('con los minutos por kilómetro en cero vuelve a ser un plazo único', function () {
    Ajustes::guardar(['operacion.minutos_por_km' => 0], null);
    $plazos = app(App\Services\PlazoPorDistancia::class);

    expect($plazos->minutosPara(0.5))->toBe(20)
        ->and($plazos->minutosPara(9.0))->toBe(20);
});

test('un tope mal puesto nunca promete menos que la base', function () {
    // Menos que la base no tiene sentido: es lo que cuesta preparar y salir.
    Ajustes::guardar(['operacion.tiempo_entrega_max' => 10], null);
    $plazos = app(App\Services\PlazoPorDistancia::class);

    expect($plazos->minutosPara(8.0))->toBe(20);
});

test('cada tienda anuncia su propio plazo en el listado', function () {
    // Con sus propios valores: otra prueba de este archivo deja los minutos
    // por kilómetro en cero, y los ajustes viven en la base.
    Ajustes::guardar([
        'operacion.tiempo_entrega_min' => 20,
        'operacion.minutos_por_km'     => 4,
        'operacion.tiempo_entrega_max' => 60,
    ], null);
    $plazos = app(App\Services\PlazoPorDistancia::class);

    /*
     * Lo que se ve en la lista: la tienda de la esquina no puede anunciar el
     * mismo tiempo que la del otro barrio, que es lo que pasaba con un plazo
     * único para todos.
     */
    expect($plazos->minutosPara(0.3))->toBeLessThan($plazos->minutosPara(4.0));
});

test('una entrega dentro del plazo cuenta como a tiempo', function () {
    $order = pedidoConPlazo(prometidos: 20, tardo: 18);

    expect($order->delivery_minutes)->toBe(18)
        ->and($order->on_time)->toBeTrue()
        ->and($order->delay_minutes)->toBe(0);
});

test('una entrega pasada del plazo cuenta el retraso', function () {
    $order = pedidoConPlazo(prometidos: 20, tardo: 33);

    expect($order->on_time)->toBeFalse()
        ->and($order->delay_minutes)->toBe(13);
});

test('justo en el minuto del plazo todavía es a tiempo', function () {
    // El límite se cumple, no se incumple: 20 de 20 llegó a tiempo.
    expect(pedidoConPlazo(prometidos: 20, tardo: 20)->on_time)->toBeTrue();
});

test('cambiar el estándar no reescribe lo ya entregado', function () {
    // La razón de ser de la columna. Se entregó en 25 con un plazo de 20:
    // llegó tarde, y sube quien suba el ajuste después, sigue llegando tarde.
    $order = pedidoConPlazo(prometidos: 20, tardo: 25);
    expect($order->on_time)->toBeFalse();

    Ajustes::guardar(['operacion.tiempo_entrega_min' => 40], null);

    expect($order->fresh()->on_time)->toBeFalse()
        ->and($order->fresh()->promised_minutes)->toBe(20);
});

test('un pedido sin entregar no cuenta ni a favor ni en contra', function () {
    // Null y no false: de una entrega que no ha ocurrido no hay nada que decir.
    $id = DB::table('orderssales')->insertGetId([
        'total'            => 12000,
        'sale_date'        => now(),
        'dispatched_at'    => now(),
        'promised_minutes' => 20,
        'state'            => 3,
    ], 'orderSales_id');

    $order = OrdersSales::find($id);

    expect($order->delivery_minutes)->toBeNull()
        ->and($order->on_time)->toBeNull()
        ->and($order->delay_minutes)->toBeNull();
});

test('una entrega anterior al compromiso tampoco se juzga', function () {
    // Los pedidos que se entregaron antes de que existiera el plazo no tienen
    // contra qué medirse; contarlos como incumplidos sería inventar.
    $order = pedidoConPlazo(prometidos: 20, tardo: 30);
    $order->promised_minutes = null;
    $order->save();

    expect($order->fresh()->on_time)->toBeNull();
});

test('un pedido en camino no reporta tiempo de entrega', function () {
    /*
     * El caso que apareció en pantalla: hasta hace poco `delivery_date` se
     * rellenaba con `now()` al CREAR el pedido. Los creados así siguen
     * arrastrándolo, así que uno todavía en camino tenía fecha de entrega
     * anterior a su despacho y el historial decía "Entregado a tiempo · -1392
     * min de 20" sobre un pedido que iba de camino.
     */
    $id = DB::table('orderssales')->insertGetId([
        'total'            => 12000,
        'sale_date'        => now()->subDay(),
        // La marca vieja: anterior al despacho.
        'delivery_date'    => now()->subDay(),
        'dispatched_at'    => now()->subHour(),
        'promised_minutes' => 20,
        'state'            => 3,
    ], 'orderSales_id');

    $order = OrdersSales::find($id);

    expect($order->delivery_minutes)->toBeNull()
        ->and($order->on_time)->toBeNull()
        ->and($order->delay_minutes)->toBeNull();
});

test('una entrega anterior a su despacho no se juzga', function () {
    // Datos incoherentes: la respuesta honesta es "no se sabe", no un negativo.
    $despacho = now()->subHour();

    $id = DB::table('orderssales')->insertGetId([
        'total'            => 12000,
        'sale_date'        => $despacho->copy()->subMinutes(30),
        'delivery_date'    => $despacho->copy()->subMinutes(10),
        'dispatched_at'    => $despacho,
        'promised_minutes' => 20,
        'state'            => 4,
    ], 'orderSales_id');

    expect(OrdersSales::find($id)->delivery_minutes)->toBeNull();
});

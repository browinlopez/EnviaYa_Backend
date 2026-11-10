<?php

/**
 * LA VITRINA: EL NÚMERO DE CONSULTAS NO PUEDE CRECER CON EL DE TIENDAS.
 *
 * Misma prueba que la del tablero y mismo motivo (§25), pero sobre
 * `GET /businesses/index`, que es **la lectura de más tráfico de la
 * plataforma**: la hace cada comprador cada vez que abre la app. Un N+1 aquí
 * cuesta más que en cualquier otro sitio del backend.
 *
 * `ConsultaDeNegociosController::index()` recorre los negocios y de cada uno
 * lee el municipio, sus dueños, sus productos y sus reseñas, todo a través de
 * relaciones. La consulta no precargaba ninguna.
 *
 * SE MIDEN LAS DOS FORMAS de llamarla, porque son dos caminos distintos:
 *
 *   ligero=1   lo que pide la app al arrancar: sin productos ni reseñas
 *   completa   con todo dentro
 *
 * La segunda es la que puede doler de verdad, y es justo la que una prueba
 * con un solo negocio no distingue de la primera.
 *
 * ESTA PRUEBA NO ENCONTRÓ NADA, y se queda igual.
 *
 * Medido: **5 consultas con una tienda y 5 con veinte**, en las dos formas.
 * La vitrina ya precargaba bien —`Business::with([...])`, con la sintaxis
 * estática, que es por lo que un barrido buscando `->with(` la señaló como
 * sospechosa y era un falso positivo mío—.
 *
 * Se queda porque es la lectura de más tráfico de la plataforma y lo que
 * vigila es que **siga** plana: añadir un campo que venga de una relación sin
 * añadirla al `with` es un cambio de una línea, y es exactamente lo que pasó
 * en el tablero de pedidos. Una prueba que hoy no encuentra nada no es una
 * prueba de más; es la que avisa el día que alguien lo rompe.
 *
 * DE PASO, algo que conviene saber: `ligero` cuesta **las mismas 5
 * consultas** que la completa. No ahorra base de datos, ahorra serialización
 * y tamaño de respuesta. Si alguna vez hace falta que ahorre consultas, hay
 * que hacer condicional el `with`, no solo la salida.
 */

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * `$cuantas` tiendas, cada una COMPLETA: municipio, dueño, producto y reseña.
 *
 * Cada una con los suyos propios, no compartidos. Si todas tuvieran el mismo
 * municipio o el mismo dueño, Eloquent resolvería la relación una vez y el
 * N+1 no aparecería ni estando el defecto: la prueba se vería verde sobre el
 * código roto.
 */
function vitrinaCon(int $cuantas): void
{
    foreach ([1 => 'comprador', 2 => 'tendero'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }

    /*
     * El país se reusa: `iso_code` es único y estas pruebas llaman al
     * escenario DOS veces —una con una tienda y otra con cinco más— para
     * poder comparar. Insertarlo a ciegas la segunda vez choca.
     */
    $pais = DB::table('countries')->where('iso_code', 'CO')->value('id')
        ?? DB::table('countries')->insertGetId(['name' => 'Colombia', 'iso_code' => 'CO'], 'id');
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    for ($i = 0; $i < $cuantas; $i++) {
        // Departamento y municipio propios: el municipio es una de las
        // relaciones que se leen por tienda.
        $depto = DB::table('departments')->insertGetId([
            'name' => 'Depto '.$i, 'country_id' => $pais,
        ], 'id');
        $muni = DB::table('municipalities')->insertGetId([
            'name' => 'Municipio '.$i, 'department_id' => $depto,
        ], 'id');

        $negocio = DB::table('business')->insertGetId([
            'name' => 'Tienda '.$i, 'qualification' => 4, 'state' => 1, 'type' => $tipo,
            'municipality_id' => $muni,
            'latitude' => 11.0 + ($i / 100), 'longitude' => -74.8,
            'accepting_orders' => 1,
        ], 'busines_id');

        $tendero = User::factory()->create(['rol' => 2]);
        $ownerId = DB::table('owner')->insertGetId([
            'user_id' => $tendero->user_id, 'state' => 1,
        ], 'owner_id');
        DB::table('owner_busines')->insert([
            'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
        ]);

        $producto = DB::table('products')->insertGetId([
            'name' => 'Arroz '.$i, 'state' => 1,
        ], 'products_id');
        DB::table('products_business')->insert([
            'busines_id' => $negocio, 'products_id' => $producto,
            'price' => 5000, 'amount' => 10, 'qualification' => 0,
        ]);

        // La reseña va por `buyer_id`, no por `user_id`: es el comprador, no
        // la cuenta.
        $comprador = User::factory()->create(['rol' => 1]);
        $buyer = DB::table('buyer')->insertGetId([
            'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
        ]);
        DB::table('business_reviews')->insert([
            'busines_id' => $negocio, 'buyer_id' => $buyer,
            'qualification' => 5, 'comment' => 'Bien', 'state' => 1,
        ]);
    }

    // `businesses/index` pide sesion: la vitrina no es publica.
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));
}

/**
 * Cuántas consultas cuesta pedir la vitrina.
 *
 * La primera petición del proceso paga además el catálogo de ajustes
 * (`platform_settings`), que luego se cachea, así que se pide dos veces y se
 * cuenta la segunda. Sin esto la comparación falla por ±1 y por un motivo que
 * no tiene nada que ver con las tiendas.
 */
function consultasDeLaVitrina(bool $ligero): int
{
    $parametros = $ligero ? ['ligero' => 1] : [];

    test()->getJson('/v1/businesses/index?'.http_build_query($parametros))->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->getJson('/v1/businesses/index?'.http_build_query($parametros))->assertOk();

    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

test('la vitrina ligera cuesta lo mismo con una tienda que con seis', function () {
    // Lo que pide la app al arrancar.
    vitrinaCon(1);
    $conUna = consultasDeLaVitrina(true);

    vitrinaCon(5);  // seis en total
    $conSeis = consultasDeLaVitrina(true);

    expect($conSeis)->toBe($conUna);
});

test('la vitrina completa cuesta lo mismo con una tienda que con seis', function () {
    /*
     * La que puede doler: de cada tienda saca además sus productos y sus
     * reseñas. Con una sola tienda no se distingue de la ligera, que es por
     * lo que un N+1 aquí puede vivir años.
     */
    vitrinaCon(1);
    $conUna = consultasDeLaVitrina(false);

    vitrinaCon(5);
    $conSeis = consultasDeLaVitrina(false);

    expect($conSeis)->toBe($conUna);
});

test('la vitrina devuelve las seis tiendas con su municipio y su dueño', function () {
    /*
     * LA CONTRAPARTE. Una vitrina vacía también costaría lo mismo con una y
     * con seis tiendas, y pasaría en verde: hay que comprobar que los datos
     * que se cargan por relación están de verdad ahí.
     */
    vitrinaCon(6);

    $r = test()->getJson('/v1/businesses/index')->assertOk();
    $tiendas = $r->json('businesses') ?? $r->json('data') ?? $r->json();

    expect($tiendas)->toHaveCount(6);

    foreach ($tiendas as $t) {
        expect($t['municipality'])->not->toBeNull();
        expect($t['municipality']['name'])->not->toBeEmpty();
        expect($t['owner_count'])->toBe(1);
    }
});

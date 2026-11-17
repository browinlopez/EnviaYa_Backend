<?php

/**
 * LA FORMA DE LA RESPUESTA NO CAMBIA PORQUE SE MUEVA DE CLASE.
 *
 * Hermana de `SuperficieDeLaApiTest`: esa congela QUÉ rutas hay, esta congela
 * QUÉ DEVUELVEN. Nació con la primera tanda de `JsonResource` y existe porque
 * esa migración no se puede hacer a ciegas.
 *
 * El estándar pide que la forma de la respuesta viva en un `JsonResource` y
 * no en el modelo ni en un servicio. Mover cien líneas de nombres de campo de
 * un sitio a otro **no debe cambiar un solo byte** de lo que recibe la app
 * publicada en las dos tiendas, y la suite que había no lo garantizaba:
 * todas las pruebas miran campos concretos —`total`, `change_due`— y ninguna
 * compara la respuesta entera. Un campo que se cayera al mover el método no
 * habría puesto roja ninguna.
 *
 * QUÉ SE CONGELA: la forma, no los valores. De cada respuesta se guarda el
 * camino de cada clave y el TIPO de lo que hay al final —`string`, `integer`,
 * `null`, `array`…—, no la cifra. Los valores dependen de los datos que
 * siembre la prueba y cambiarían cada vez; la forma es el contrato.
 *
 * Un `null` cuenta como tipo a propósito: `country` viaja siempre nulo y las
 * apps leen la clave (ver `UserAddress::country()`). Si alguien «limpiara»
 * esa clave por estar vacía, esto se pone rojo.
 *
 * SI EL CAMBIO ES QUERIDO, se regenera en el mismo PR y la revisión ve la
 * diferencia campo a campo, que es cuando alguien tiene que preguntarse a qué
 * cliente publicado le afecta:
 *
 *     CONGELAR_FORMA=1 vendor/bin/pest tests/Feature/Contrato/FormaDeLaRespuestaTest.php
 *
 * LO QUE ESTA PRUEBA NO ES. No sustituye a la comparación byte a byte que se
 * hizo contra la base de desarrollo al migrar —1,6 MB de JSON real, cuatro
 * endpoints, cero diferencias—, porque aquí los datos son los que siembra la
 * prueba y no cubren todas las ramas. Es la red que QUEDA, para el día de
 * mañana; la comparación fue la medida del día de la mudanza.
 */

use App\Models\Rol;
use App\Models\User;
use App\Services\BoldService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * El camino de cada clave con el tipo de su valor, en plano y ORDENADO.
 *
 * `['order' => ['total' => '1.0']]` da `['order.total' => 'string']`. De una
 * lista se mira SOLO EL PRIMER ELEMENTO y se marca con `[]`: los demás tienen
 * la misma forma, y guardar los cien pedidos de una lista haría un fichero
 * que cambia con los datos en vez de con el contrato.
 *
 * SE ORDENA POR CLAVE, Y ESO LO ENSEÑÓ EL CI DE MySQL. `toBe()` compara
 * arreglos incluyendo el orden, y el orden de las claves de una respuesta que
 * lleva un modelo de Eloquent crudo ES EL ORDEN DE LAS COLUMNAS de su tabla.
 * Ahí los dos motores no coinciden: MySQL coloca la columna donde dice
 * `->after('x')` en la migración y **SQLite ignora `after()` y la añade al
 * final**. `POST /v1/reviews/store` fallaba con los tipos idénticos y solo el
 * orden distinto; las otras entradas no lo sufrían porque pasan por un
 * `Resource`, que arma el arreglo en orden explícito.
 *
 * Y ordenar no afloja nada: el orden de las claves de un objeto JSON no es
 * contrato --el cliente las lee por nombre, no por posición--. Lo que sí es
 * contrato es QUÉ claves hay y de qué tipo, y eso se sigue comparando entero.
 * El orden de una LISTA sí importa, pero de una lista solo se mira el primer
 * elemento, así que esto no lo toca.
 */
function formaDe(mixed $valor, string $camino = ''): array
{
    if (is_array($valor) && $valor !== [] && array_is_list($valor)) {
        return formaDe($valor[0], $camino.'[]');
    }

    if (is_array($valor) && ! array_is_list($valor)) {
        $fuera = [];
        foreach ($valor as $clave => $dentro) {
            $fuera += formaDe($dentro, $camino === '' ? (string) $clave : $camino.'.'.$clave);
        }

        ksort($fuera);

        return $fuera;
    }

    return [$camino => get_debug_type($valor)];
}

/** Un pedido con TODO puesto: comprador, repartidor, dirección y promoción. */
function pedidoCompletoParaLaForma(): array
{
    $e = escenarioPedido();

    $r = test()->postJson('/v1/orders/orders', cuerpoPedido($e))->assertCreated();
    $id = (int) $r->json('order.order_id');

    /*
     * SE CREA EL REPARTIDOR AQUÍ, Y ESTO COSTÓ UNA VUELTA.
     *
     * La primera versión hacía `DB::table('domiciliary')->value(...)` y, como
     * `escenarioPedido()` no crea ninguno, salía `null`: el pedido quedaba sin
     * repartidor, la clave `domiciliary` viajaba nula y **la forma congelada
     * no incluía ninguno de sus cinco campos**. La prueba habría quedado en
     * verde vigilando media respuesta.
     *
     * Lo encontró la prueba de clavo del final de este archivo, que es justo
     * para lo que está. Es el mismo error que ya había pasado en
     * `ElClienteNoPoneElPrecioTest`: un escenario sin repartidor hace que una
     * aserción sobre repartidores se satisfaga sola.
     *
     * Se asigna a mano y no por la ruta de despacho porque lo que se congela
     * es la forma, no el flujo: el despacho tiene sus propias pruebas.
     */
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);
    $repartidor = DB::table('domiciliary')->insertGetId([
        'user_id' => User::factory()->create(['rol' => 3])->user_id,
        'available' => 1,
        'qualification' => 0,
        'state' => 1,
    ], 'domiciliary_id');

    expect($repartidor)->toBeGreaterThan(0);

    DB::table('orderssales')->where('orderSales_id', $id)
        ->update(['domiciliary_id' => $repartidor]);

    return ['escenario' => $e, 'order_id' => $id, 'respuesta' => $r];
}

/**
 * La forma congelada de una respuesta, y su CODIGO DE ESTADO.
 *
 * El estado se guarda junto a la forma, y no se deduce del verbo, porque
 * deducirlo sale mal: `POST /v1/orders/business` es un POST y devuelve 200,
 * porque es una LECTURA que manda el filtro en el cuerpo. El generador de
 * OpenAPI daba por hecho que todo POST contesta 201 y por eso el contrato
 * afirmaba un codigo que nadie habia medido. Lo encontro la prueba de punta a
 * punta de `ContratoOpenApiTest`.
 */
function formaCongelada(string $clave, array $viva, ?int $estado = null): array
{
    $ruta = __DIR__.'/forma-de-las-respuestas.json';

    $todo = is_file($ruta)
        ? (array) json_decode((string) file_get_contents($ruta), true)
        : [];

    if (env('CONGELAR_FORMA')) {
        $todo[$clave] = ['estado' => $estado, 'forma' => $viva];
        ksort($todo);
        file_put_contents(
            $ruta,
            json_encode($todo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        return $viva;
    }

    /*
     * `toHaveKey($clave, $algo)` NO lleva mensaje: el segundo argumento de
     * Pest es el VALOR esperado de esa clave. Usarlo como mensaje compara el
     * mensaje contra el valor y la prueba falla diciendo «two strings are not
     * equal», que no es lo que pasa. Por eso acá va la comprobación a secas.
     */
    expect(array_key_exists($clave, $todo))->toBeTrue(
        "No hay forma congelada para «{$clave}». Si es nueva: CONGELAR_FORMA=1",
    );

    // El estado tambien se comprueba: una respuesta con la misma forma y otro
    // codigo es otro contrato.
    if ($estado !== null) {
        expect((int) ($todo[$clave]['estado'] ?? 0))->toBe($estado);
    }

    return (array) ($todo[$clave]['forma'] ?? []);
}

it('crear un pedido devuelve la forma congelada', function () {
    // `PedidoResource`, que era `OrdersSales::toApi()`.
    $x = pedidoCompletoParaLaForma();

    $viva = formaDe($x['respuesta']->json());

    expect($viva)->toBe(formaCongelada('POST /v1/orders/orders', $viva, 201));
});

it('la lista del tendero devuelve la forma congelada', function () {
    /*
     * `PedidoDeLaAppResource` con los dos interruptores en `true`, más
     * `NegocioDelPedidoResource` en la clave `business`. Es la respuesta más
     * grande de la tanda y la que más campos distintos trae.
     */
    $x = pedidoCompletoParaLaForma();

    /*
     * HAY QUE DARLE DUEÑO AL NEGOCIO, porque `escenarioPedido()` lo crea sin
     * uno —no le hace falta para crear un pedido— y esta ruta resuelve la
     * tienda por `owner_busines`. Sin esto son 403, y congelar la forma de un
     * 403 habría dejado una red que vigila un mensaje de error.
     */
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId([
        'user_id' => $tendero->user_id, 'state' => 1,
    ], 'owner_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId,
        'busines_id' => $x['escenario']['businessId'],
        'state' => 1,
    ]);

    Sanctum::actingAs($tendero);

    $r = test()->postJson('/v1/orders/business', [
        'business_id' => $x['escenario']['businessId'],
    ])->assertOk();

    $viva = formaDe($r->json());

    expect($viva)->toBe(formaCongelada('POST /v1/orders/business', $viva, 200));
});

it('cancelar un pedido devuelve la forma congelada', function () {
    $x = pedidoCompletoParaLaForma();

    $r = test()->putJson('/v1/orders/'.$x['order_id'].'/cancel', [
        'reason' => 'me equivoqué',
    ]);

    // Si la cancelación no procede en este escenario, la forma de ESA
    // respuesta no es la que esta prueba vigila: se dice y se para, en vez de
    // congelar un error como si fuera el contrato.
    expect($r->status())->toBe(200, 'La cancelación no dio 200: '.$r->getContent());

    $viva = formaDe($r->json());

    expect($viva)->toBe(formaCongelada('PUT /v1/orders/{id}/cancel', $viva, 200));
});

/*
|--------------------------------------------------------------------------
| LAS CINCO QUE LA APP LLAMA Y NINGUNA PRUEBA TOCABA
|--------------------------------------------------------------------------
|
| Salieron de medir el reparto: de las 101 rutas que llama la app, 71 tenian
| su forma afirmada por alguna prueba, 25 se llamaban sin afirmar nada y
| CINCO no las tocaba ningun fichero de prueba. Ninguna de las cinco cambio
| con el despliegue del 16 de noviembre de 2026 --se comparo metodo a metodo
| contra `92ecb00`--, asi que hoy no rompen nada; lo que no tenian era red
| para el dia de manana.
|
| `GET /v1/payment/status` es la mas delicada de las cinco: es la que la app
| SONDEA cada pocos segundos despues de pagar.
*/

it('el estado del pago devuelve la forma congelada', function () {
    $pedido = (int) DB::table('orderssales')->insertGetId([
        'subtotal' => 12000, 'domicilio' => 3000, 'total' => 15000,
        'currency' => 'COP', 'sale_date' => now(), 'state' => 1,
        'payment_state' => 'pending_online',
    ], 'orderSales_id');

    DB::table('payment_intents')->insert([
        'orderSales_id' => $pedido,
        'provider' => 'bold',
        'bold_reference_id' => 'REF-DE-PRUEBA',
        'amount' => 15000,
        'currency' => 'COP',
        'status' => 'running',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /*
     * BOLD SE FINGE, Y HAY QUE DECIR QUE CONGELA ESO Y QUE NO.
     *
     * `data` es el cuerpo de Bold tal cual, asi que su forma la manda Bold y
     * no este repositorio: lo que queda congelado ahi es el cuerpo del doble,
     * no una promesa de la pasarela. Lo que SI es contrato nuestro es el nivel
     * de arriba --`payment_status`, `order_payment_state`, `status`, y que
     * `data` viaje--, que es lo que lee la app.
     *
     * Se responde `PROCESSING` a proposito: es un estado NO final, asi que el
     * endpoint no toca el pedido ni dispara `ConfirmacionDePago`. Aca se mide
     * la forma; el flujo de aprobacion tiene sus propias pruebas.
     */
    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('checkPayment')->andReturn([
        'status' => 'PROCESSING',
        'transaction_id' => 'TX-QUE-NO-EXISTE-AUN',
    ]);
    app()->instance(BoldService::class, $bold);

    Sanctum::actingAs(User::factory()->create());

    $r = test()->getJson('/v1/payment/status?referenceId='.$pedido);
    expect($r->status())->toBe(200, 'El sondeo no dio 200: '.$r->getContent());

    $viva = formaDe($r->json());

    /*
     * Control de ceguera. Sin esto, el dia que el endpoint devolviera `{}` se
     * regeneraria un congelado vacio y la comparacion de abajo seguiria en
     * verde vigilando nada.
     */
    expect($viva)->toHaveKeys(['payment_status', 'order_payment_state', 'status']);

    expect($viva)->toBe(formaCongelada('GET /v1/payment/status', $viva, 200));
});

it('los bancos de PSE devuelven la forma congelada', function () {
    /*
     * El doble manda tambien el banco de mentira que Bold mete en su lista
     * --codigo 0, que es el texto de su propio selector-- para que la forma se
     * tome DESPUES del filtro, que es lo que de verdad recibe la app.
     */
    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('bancosPse')->andReturn([
        'banks' => [
            ['bank_code' => 0, 'bank_name' => 'A continuacion seleccione su banco'],
            ['bank_code' => 1022, 'bank_name' => 'Banco Union'],
        ],
    ]);
    app()->instance(BoldService::class, $bold);

    // La ruta NO es publica, aunque la lista de bancos no tenga nada privado:
    // va dentro del grupo con `auth:sanctum`. Sin sesion responde 401.
    Sanctum::actingAs(User::factory()->create());

    $r = test()->getJson('/v1/pse/bancos');
    expect($r->status())->toBe(200, $r->getContent());

    // Control: con la lista vacia la forma seria `data` a secas, sin
    // `bank_code` ni `bank_name`; una red sobre nada.
    expect($r->json('data'))->toHaveCount(1);

    $viva = formaDe($r->json());
    expect($viva)->toBe(formaCongelada('GET /v1/pse/bancos', $viva, 200));
});

it('las tiendas para copiar devuelven la forma congelada', function () {
    $e = tenderoConCatalogo();
    enLaTienda($e['vecino'], productoDeCatalogo('Arroz Diana')->products_id);

    Sanctum::actingAs($e['user']);

    $r = test()->getJson('/v1/negocio/productos/tiendas-para-copiar');
    expect($r->status())->toBe(200, $r->getContent());

    /*
     * Control de ceguera, y aca hace falta de verdad: este endpoint devuelve
     * la lista vacia siempre que no haya otra tienda del mismo tipo CON
     * surtido, y una lista vacia congela una forma sin ninguno de sus tres
     * campos. Es el mismo error que ya documenta `pedidoCompletoParaLaForma()`
     * con el repartidor que no existia.
     */
    expect($r->json('data'))->toHaveCount(1);

    $viva = formaDe($r->json());
    expect($viva)->toBe(formaCongelada('GET /v1/negocio/productos/tiendas-para-copiar', $viva, 200));
});

it('marcar todos los avisos devuelve la forma congelada', function () {
    $u = User::factory()->create();

    DB::table('notifications')->insert([
        'user_id' => $u->user_id,
        'message' => 'Tu pedido va en camino',
        'read' => 0,
        'date' => now(),
        'state' => 1,
    ]);

    Sanctum::actingAs($u);

    $r = test()->putJson('/v1/users/notifications/read-all');
    expect($r->status())->toBe(200, $r->getContent());

    // Control: con cero avisos sin leer, `count` valdria 0 y la prueba pasaria
    // sin que el endpoint hubiera marcado nada.
    expect($r->json('count'))->toBe(1);

    $viva = formaDe($r->json());
    expect($viva)->toBe(formaCongelada('PUT /v1/users/notifications/read-all', $viva, 200));
});

it('crear una resena devuelve la forma congelada', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $u = User::factory()->create(['rol' => 1]);

    $comprador = (int) DB::table('buyer')->insertGetId([
        'user_id' => $u->user_id, 'qualification' => 0, 'state' => 1,
    ], 'buyer_id');
    expect($comprador)->toBeGreaterThan(0);

    $negocio = (int) DB::table('business')->insertGetId([
        'name' => 'La de la resena', 'qualification' => 0, 'state' => 1,
    ], 'busines_id');

    Sanctum::actingAs($u);

    $r = test()->postJson('/v1/reviews/store', [
        'user_id' => $u->user_id,
        'type' => 'business',
        'id' => $negocio,
        'qualification' => 5,
        'comment' => 'Llego rapido y bien empacado',
    ]);
    expect($r->status())->toBe(201, 'La resena no se creo: '.$r->getContent());

    $viva = formaDe($r->json());

    // Control: el contrato de la app es `review` con su cuerpo dentro, no un
    // `message` a secas.
    expect($viva)->toHaveKey('review.qualification');

    expect($viva)->toBe(formaCongelada('POST /v1/reviews/store', $viva, 201));
});

it('la forma congelada cubre los campos que mas duelen', function () {
    /*
     * Prueba de clavo sobre el propio fichero congelado. Sin esto, alguien
     * podría regenerarlo con un escenario pobre —sin repartidor, sin
     * dirección— y el fichero quedaría «verde» vigilando la mitad de los
     * campos. El síntoma no sería un error: sería una red con agujeros.
     */
    $ruta = __DIR__.'/forma-de-las-respuestas.json';
    $todo = (array) json_decode((string) file_get_contents($ruta), true);

    /*
     * Se junta lo que falta y se afirma la lista vacía, en vez de un
     * `toHaveKey` por campo: así el fallo NOMBRA los campos que faltan en vez
     * de pararse en el primero. Y `toHaveKey` no sirve acá de todos modos —su
     * segundo argumento es el valor esperado de la clave, no un mensaje—.
     */
    $faltan = [];

    $exigir = function (array $forma, array $campos, string $donde) use (&$faltan) {
        foreach ($campos as $campo) {
            if (! array_key_exists($campo, $forma)) {
                $faltan[] = $donde.' -> '.$campo;
            }
        }
    };

    $exigir((array) ($todo['POST /v1/orders/business']['forma'] ?? []), [
        'orders[].domiciliary.domiciliary_id',
        'orders[].delivery_address.address',
        'orders[].delivery_address.country',
        'orders[].details[].line_total',
        'orders[].details[].sale_unit',
        'orders[].cash_tendered',
        'orders[].change_due',
        'business.qualification',
    ], 'la lista del tendero');

    $exigir((array) ($todo['POST /v1/orders/orders']['forma'] ?? []), [
        'order.weighing_state',
        'order.promised_minutes',
        'order.business.logo',
        'order.details[].orderDet_id',
    ], 'crear un pedido');

    expect($faltan)->toBe([]);
});

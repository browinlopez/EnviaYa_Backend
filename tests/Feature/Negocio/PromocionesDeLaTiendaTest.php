<?php

use App\Models\Promotion;
use App\Models\Rol;
use App\Models\User;
use App\Services\Ajustes;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LA PROMOCIÓN ESCRITA DEL TENDERO.
 *
 * Es un aviso, no un descuento que se aplique solo: el tendero escribe una
 * frase, marca a qué productos se refiere, y les llega a sus clientes
 * afiliados. Lo que se comprueba acá es que llegue a QUIEN DEBE y solo a quien
 * debe, porque ese es el daño posible: una promoción sale al teléfono de gente
 * real con el nombre de una tienda encima, y no se puede retirar.
 *
 * Las dos que más importan son la del producto ajeno —anunciar algo que no
 * vendes— y la del tope diario, que existe para que la gente no silencie los
 * avisos de la app entera por culpa de una tienda pesada.
 */

/** Un tendero con su tienda, un producto suyo y `$afiliados` clientes. */
function tiendaConAfiliados(int $afiliados = 2): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);

    $tendero = User::factory()->create(['rol' => 2]);

    $ownerId = DB::table('owner')->insertGetId([
        'user_id' => $tendero->user_id,
        'state' => 1,
    ], 'owner_id');

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $businessId = DB::table('business')->insertGetId([
        'name' => 'Tienda el progreso', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $businessId, 'state' => 1,
    ]);

    $productoId = DB::table('products')->insertGetId([
        'name' => 'Atún Van Camps', 'state' => 1,
    ], 'products_id');

    DB::table('products_business')->insert([
        'busines_id' => $businessId, 'products_id' => $productoId, 'price' => 5000, 'amount' => 10,
    ]);

    $clientes = [];

    for ($i = 0; $i < $afiliados; $i++) {
        $cliente = User::factory()->create(['rol' => 1]);

        DB::table('business_user_affiliations')->insert([
            'user_id' => $cliente->user_id, 'busines_id' => $businessId,
        ]);

        $clientes[] = $cliente;
    }

    return compact('tendero', 'businessId', 'productoId', 'clientes');
}

it('la promocion le llega a los clientes afiliados y a nadie mas', function () {
    ['tendero' => $tendero, 'clientes' => $clientes] = tiendaConAfiliados(2);

    // Alguien registrado que NO se afilió a esta tienda.
    $ajeno = User::factory()->create(['rol' => 1]);

    Sanctum::actingAs($tendero);

    $r = $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Compra 2 atunes y el tercero va gratis, solo hoy.',
    ]);

    $r->assertStatus(201)->assertJsonPath('promocion.destinatarios', 2);

    foreach ($clientes as $cliente) {
        expect(DB::table('notifications')
            ->where('user_id', $cliente->user_id)
            ->where('tipo', 'promocion')
            ->count())->toBe(1);
    }

    expect(DB::table('notifications')->where('user_id', $ajeno->user_id)->count())->toBe(0);
});

it('el aviso lleva el texto tal cual y el nombre de la tienda', function () {
    ['tendero' => $tendero, 'clientes' => $clientes] = tiendaConAfiliados(1);

    Sanctum::actingAs($tendero);

    $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Lleva 3 y paga 2 en toda la nevera.',
    ])->assertStatus(201);

    $aviso = DB::table('notifications')->where('user_id', $clientes[0]->user_id)->first();

    expect($aviso->message)->toBe('Lleva 3 y paga 2 en toda la nevera.');

    // El nombre viaja EN el aviso: si la tienda se renombra, el aviso viejo
    // tiene que seguir diciendo lo que decía cuando llegó.
    expect(json_decode($aviso->datos, true)['business_name'])->toBe('Tienda el progreso');
});

it('no se puede anunciar un producto que no vende esta tienda', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(1);

    // Existe en el catálogo maestro, pero es de otra tienda.
    $ajeno = DB::table('products')->insertGetId([
        'name' => 'Producto de otra tienda', 'state' => 1,
    ], 'products_id');

    Sanctum::actingAs($tendero);

    $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Promocion sobre algo que no vendo',
        'productos' => [$ajeno],
    ])->assertStatus(422)->assertJsonPath('reason', 'producto_ajeno');

    expect(DB::table('promotions')->count())->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(0);
});

it('los productos elegidos quedan atados a la promocion', function () {
    ['tendero' => $tendero, 'productoId' => $productoId] = tiendaConAfiliados(1);

    Sanctum::actingAs($tendero);

    $r = $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Compra 2 atunes y el tercero va gratis.',
        'productos' => [$productoId, $productoId],
    ]);

    $r->assertStatus(201)->assertJsonCount(1, 'promocion.productos');

    // El mismo producto dos veces no significa nada y saldría repetido.
    expect(DB::table('promotion_products')->count())->toBe(1);
});

it('el tope diario lo pone el panel y frena la de mas', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(1);

    Ajustes::guardar(['operacion.promociones_por_dia' => 1], null);

    Sanctum::actingAs($tendero);

    $this->postJson('/v1/negocio/promociones', ['texto' => 'La primera del dia'])
        ->assertStatus(201);

    $this->postJson('/v1/negocio/promociones', ['texto' => 'La segunda del dia'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'limite_diario');

    expect(DB::table('promotions')->count())->toBe(1);
});

it('una tienda no puede mandar promociones en nombre de otra', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(1);
    $vecina = tiendaConAfiliados(3);

    Sanctum::actingAs($tendero);

    // Manda el identificador de la tienda vecina en el cuerpo. El middleware
    // `negocio` lo pisa con el suyo, así que la promoción sale de SU tienda.
    $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Promocion en nombre del vecino',
        'business_id' => $vecina['businessId'],
    ])->assertStatus(201)->assertJsonPath('promocion.destinatarios', 1);

    expect(DB::table('promotions')->where('busines_id', $vecina['businessId'])->count())->toBe(0);

    foreach ($vecina['clientes'] as $cliente) {
        expect(DB::table('notifications')->where('user_id', $cliente->user_id)->count())->toBe(0);
    }
});

it('sin clientes afiliados se guarda pero se dice que no salio a nadie', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(0);

    Sanctum::actingAs($tendero);

    $r = $this->postJson('/v1/negocio/promociones', ['texto' => 'Promocion sin publico'])
        ->assertStatus(201)
        ->assertJsonPath('promocion.destinatarios', 0);

    // Cantar "enviada" sobre cero personas dejaría al tendero sin entender por
    // qué su promoción no vendió nada.
    expect($r->json('message'))->toContain('todavía no tienes clientes');
});

it('una cuenta desactivada no recibe promociones', function () {
    ['tendero' => $tendero, 'clientes' => $clientes] = tiendaConAfiliados(2);

    DB::table('user')->where('user_id', $clientes[0]->user_id)->update(['state' => 0]);

    Sanctum::actingAs($tendero);

    $this->postJson('/v1/negocio/promociones', ['texto' => 'Promocion del dia'])
        ->assertStatus(201)
        ->assertJsonPath('promocion.destinatarios', 1);

    expect(DB::table('notifications')->where('user_id', $clientes[0]->user_id)->count())->toBe(0);
});

it('un texto que no cabe en una notificacion se rechaza', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(1);

    Sanctum::actingAs($tendero);

    $this->postJson('/v1/negocio/promociones', ['texto' => str_repeat('a', 181)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('texto');

    $this->postJson('/v1/negocio/promociones', ['texto' => '  '])
        ->assertStatus(422)
        ->assertJsonValidationErrors('texto');
});

it('la pantalla se abre sabiendo cuantos clientes hay y cuanto le queda', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(3);

    Ajustes::guardar(['operacion.promociones_por_dia' => 2], null);

    Sanctum::actingAs($tendero);

    $this->getJson('/v1/negocio/promociones')
        ->assertOk()
        ->assertJsonPath('destinatarios', 3)
        ->assertJsonPath('le_quedan_hoy', 2)
        ->assertJsonPath('maximo_letras', 180);

    $this->postJson('/v1/negocio/promociones', ['texto' => 'Una promocion cualquiera']);

    $this->getJson('/v1/negocio/promociones')
        ->assertOk()
        ->assertJsonPath('le_quedan_hoy', 1)
        ->assertJsonCount(1, 'promociones');
});

it('un comprador no puede mandar promociones', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $this->postJson('/v1/negocio/promociones', ['texto' => 'No deberia poder'])
        ->assertStatus(403);
});

/* =========================================================================
   CORREGIR, RETIRAR Y HASTA CUÁNDO DURA
   ======================================================================== */

it('corregir arregla el aviso que ya está en la campana del cliente', function () {
    ['tendero' => $tendero, 'clientes' => $clientes] = tiendaConAfiliados(1);

    Sanctum::actingAs($tendero);

    $id = $this->postJson('/v1/negocio/promociones', ['texto' => 'Compra 2 y el tercero a $500'])
        ->json('promocion.promotion_id');

    // Ya lo leyó, con el precio equivocado en la cabeza.
    DB::table('notifications')->where('user_id', $clientes[0]->user_id)->update(['read' => true]);

    $this->putJson("/v1/negocio/promociones/{$id}", ['texto' => 'Compra 2 y el tercero va gratis'])
        ->assertOk()
        ->assertJsonPath('promocion.texto', 'Compra 2 y el tercero va gratis');

    $aviso = DB::table('notifications')->where('user_id', $clientes[0]->user_id)->first();

    expect($aviso->message)->toBe('Compra 2 y el tercero va gratis')
        // Vuelve a no leído: quien ya lo leyó tiene la versión mala en la
        // cabeza y tiene que volver a verlo.
        ->and((bool) $aviso->read)->toBeFalse();
});

it('retirar le quita el aviso a todos', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(3);

    Sanctum::actingAs($tendero);

    $id = $this->postJson('/v1/negocio/promociones', ['texto' => 'Promocion que me arrepiento'])
        ->json('promocion.promotion_id');

    expect(DB::table('notifications')->where('tipo', 'promocion')->count())->toBe(3);

    $this->deleteJson("/v1/negocio/promociones/{$id}")->assertOk();

    expect(DB::table('notifications')->where('tipo', 'promocion')->count())->toBe(0);

    // Deja de salir en su lista, que es para lo que la retiró.
    $this->getJson('/v1/negocio/promociones')->assertOk()->assertJsonCount(0, 'promociones');
});

it('retirar NO devuelve el cupo del día', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(1);

    Ajustes::guardar(['operacion.promociones_por_dia' => 1], null);

    Sanctum::actingAs($tendero);

    $id = $this->postJson('/v1/negocio/promociones', ['texto' => 'La unica de hoy'])
        ->json('promocion.promotion_id');

    $this->deleteJson("/v1/negocio/promociones/{$id}")->assertOk();

    /*
     * El agujero que esto cierra: si retirar liberara el cupo, mandar-retirar
     * en bucle haría sonar el teléfono de los clientes cinco veces seguidas
     * saltándose el tope. El zumbido ya salió; el gasto está hecho.
     */
    $this->postJson('/v1/negocio/promociones', ['texto' => 'La de despues de borrar'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'limite_diario');
});

it('no se puede tocar la promocion de otra tienda', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(1);
    $vecina = tiendaConAfiliados(1);

    Sanctum::actingAs($vecina['tendero']);
    $ajena = $this->postJson('/v1/negocio/promociones', ['texto' => 'La promocion del vecino'])
        ->json('promocion.promotion_id');

    Sanctum::actingAs($tendero);

    // 404 y no 403: un "prohibido" confirmaría que esa promoción existe, y
    // probando números se sabría cuántas manda el vecino.
    $this->putJson("/v1/negocio/promociones/{$ajena}", ['texto' => 'Se la cambio'])
        ->assertStatus(404);

    $this->deleteJson("/v1/negocio/promociones/{$ajena}")->assertStatus(404);

    expect(DB::table('promotions')->where('promotion_id', $ajena)->value('description'))
        ->toBe('La promocion del vecino');
});

it('el plazo lo calcula el servidor y viaja en el aviso', function () {
    ['tendero' => $tendero, 'clientes' => $clientes] = tiendaConAfiliados(1);

    Sanctum::actingAs($tendero);

    $r = $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Solo por hoy, 20% en toda la tienda',
        'hasta' => 'hoy',
    ])->assertStatus(201);

    expect($r->json('promocion.hasta_texto'))->toBe('Solo hoy')
        ->and($r->json('promocion.vencida'))->toBeFalse();

    /*
     * El plazo va en `datos` y NO pegado al texto: pegarlo alteraría la frase
     * del tendero y además envejecería mal —mañana ese aviso seguiría diciendo
     * «solo hoy»—.
     */
    $aviso = DB::table('notifications')->where('user_id', $clientes[0]->user_id)->first();

    expect($aviso->message)->toBe('Solo por hoy, 20% en toda la tienda')
        ->and(json_decode($aviso->datos, true)['hasta_texto'])->toBe('Solo hoy');
});

it('sin plazo la promocion no vence', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(1);

    Sanctum::actingAs($tendero);

    $r = $this->postJson('/v1/negocio/promociones', ['texto' => 'Promocion sin fecha de fin'])
        ->assertStatus(201);

    expect($r->json('promocion.hasta'))->toBeNull()
        ->and($r->json('promocion.hasta_texto'))->toBeNull()
        ->and($r->json('promocion.vencida'))->toBeFalse();
});

it('una promocion vencida se ve como vencida', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(1);

    Sanctum::actingAs($tendero);

    $id = $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Promocion de la semana pasada',
        'hasta' => 'hoy',
    ])->json('promocion.promotion_id');

    // Se le mueve la fecha al pasado en vez de esperar a mañana.
    DB::table('promotions')->where('promotion_id', $id)
        ->update(['end_date' => now()->subDays(2)]);

    $this->getJson('/v1/negocio/promociones')
        ->assertOk()
        ->assertJsonPath('promociones.0.vencida', true);
});

it('un plazo inventado se rechaza', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(1);

    Sanctum::actingAs($tendero);

    $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Promocion con plazo raro',
        'hasta' => 'el_año_que_viene',
    ])->assertStatus(422)->assertJsonValidationErrors('hasta');
});

it('una promocion ya retirada no se puede corregir', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(1);

    Sanctum::actingAs($tendero);

    $id = $this->postJson('/v1/negocio/promociones', ['texto' => 'Promocion que voy a retirar'])
        ->json('promocion.promotion_id');

    $this->deleteJson("/v1/negocio/promociones/{$id}")->assertOk();

    $this->putJson("/v1/negocio/promociones/{$id}", ['texto' => 'Intento resucitarla'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'ya_retirada');
});

/* ============== EL VOCABULARIO DE UNA PROMOCION ============== */

/*
 * LAS DOS DE ACA SALIERON DE QUEDARSE SIN DEFENSA DELANTE DE LAS 1404
 * PRUEBAS, y el camino hasta saberlo importa porque casi las da por
 * defendidas.
 *
 * Al sabotearlas, la suite contestaba «2 failed». Las dos que caian eran de
 * porcentaje a tiempo, en `HistorialDePedidosTest`, un fichero que no nombra
 * `Promotion` ni `nxm`. Aislado, ese fichero fallaba esas mismas dos pruebas
 * SOBRE CODIGO SANO: las tumbaba la ventana de fechas en un lunes de
 * madrugada, no la mutacion. Con eso arreglado, las dos mutaciones pasan las
 * 1404 enteras.
 *
 * De ahi la regla que faltaba: una prueba que cae solo vale como defensa si
 * esta verde aislada. Si no, lo que se midio es otra cosa.
 */

it('los tres estados de una promocion son distintos, y BORRADOR vale 0', function () {
    /*
     * ESTA PRUEBA FIJA EL NUMERO, Y SOLO EL NUMERO. Conviene decirlo porque la
     * primera version de aca afirmaba otra cosa --que una promocion recien
     * creada queda en `state = 0`-- y es FALSO: `PromocionesDeLaTienda` la crea
     * en BORRADOR dentro de la transaccion y al salir la pisa con `ENVIADA`
     * sin condicion. La prueba fallo, y asi se supo.
     *
     * DONDE SI DECIDE ALGO: si escribir los avisos revienta --`Avisos::para`
     * no esta dentro del `try`, solo el push lo esta-- el `forceFill` de
     * ENVIADA no llega a correr y la promocion se queda en BORRADOR. Entonces
     * no aparece en la vitrina ni rebaja nada, porque los dos sitios filtran
     * `state = ENVIADA`. Si BORRADOR colisionara con ENVIADA, esa promocion a
     * medio publicar se anunciaria y descontaria dinero.
     *
     * ESE CAMINO NO LO RECORRE NINGUNA PRUEBA, y alcanzarlo pide inyectar un
     * fallo en el paso de avisos. Se dice en vez de escribir una prueba que
     * aparente cubrirlo.
     *
     * CON LITERALES: estos numeros estan en `promotions.state` de filas que ya
     * existen.
     */
    expect(Promotion::BORRADOR)->toBe(0);
    expect(Promotion::ENVIADA)->toBe(1);
    expect(Promotion::RETIRADA)->toBe(2);

    $estados = [Promotion::BORRADOR, Promotion::ENVIADA, Promotion::RETIRADA];
    expect(count(array_unique($estados)))->toBe(3);
});

it('se puede crear una promocion de cada uno de los tres tipos', function (string $tipo, array $regla) {
    ['tendero' => $tendero] = tiendaConAfiliados(1);

    Sanctum::actingAs($tendero);

    /*
     * CON EL TIPO ESCRITO, porque es lo que manda el panel por la red y
     * `GuardarPromocionRequest` lo valida contra `Promotion::TIPOS`. Si la
     * lista pierde un miembro, ese tipo deja de poderse crear: 422. Con
     * `Promotion::NXM` en la mano los dos lados saldrian de la constante y
     * esto no probaria nada.
     */
    $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Promocion de prueba para el tipo '.$tipo,
        'tipo' => $tipo,
        ...$regla,
    ])->assertStatus(201);
})->with([
    'sin descuento' => ['ninguno', []],
    'porcentaje' => ['porcentaje', ['porcentaje' => 15]],
    'N x M' => ['nxm', ['lleva' => 3, 'paga' => 2]],
]);

it('un tipo de promocion inventado se rechaza', function () {
    ['tendero' => $tendero] = tiendaConAfiliados(1);

    Sanctum::actingAs($tendero);

    /*
     * CONTROL de la de arriba. Si el endpoint contestara 201 para cualquier
     * cosa --o 422 por un campo que falta-- los tres «se acepta» no
     * significarian nada. Aca se comprueba que lo que decide es el tipo.
     */
    $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Promocion con un tipo que no existe',
        'tipo' => 'dos_por_uno',
    ])->assertStatus(422)->assertJsonValidationErrors('tipo');
});

it('los tres tipos de promocion son los que la lista blanca acepta', function () {
    /*
     * Los valores viven en `promotions.rule_type` de filas que ya existen, de
     * ahi los literales.
     */
    expect(Promotion::SIN_DESCUENTO)->toBe('ninguno');
    expect(Promotion::PORCENTAJE)->toBe('porcentaje');
    expect(Promotion::NXM)->toBe('nxm');

    expect(Promotion::TIPOS)->toBe(['ninguno', 'porcentaje', 'nxm']);
    expect(count(array_unique(Promotion::TIPOS)))->toBe(3);
});

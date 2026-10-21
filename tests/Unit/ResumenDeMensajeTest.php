<?php

use App\Support\ResumenDeMensaje;

/**
 * LO QUE LE LLEGA A LA CAMPANA CUANDO EL CHAT LLEVA UNA TARJETA.
 *
 * Esto existe por una captura: en la bandeja de avisos de una cuenta real
 * habia dos notificaciones que decian, literalmente,
 *
 *     {"type":"preorder","preorder_id":1792531797473,"status":"pending",…
 *
 * El aviso copiaba el contenido del mensaje, y el pedido que arma el tendero
 * no es texto: es un JSON que la app pinta como tarjeta.
 */

test('un mensaje normal se recorta y se manda tal cual', function () {
    expect(ResumenDeMensaje::para('¿Tiene leche deslactosada?'))
        ->toBe('¿Tiene leche deslactosada?');
});

test('un mensaje larguisimo se recorta, porque la pantalla bloqueada lo corta igual', function () {
    $resumen = ResumenDeMensaje::para(str_repeat('hola ', 60));

    expect(mb_strlen($resumen))->toBeLessThanOrEqual(123);  // 120 + los puntos
});

test('el pedido que arma el tendero se cuenta en una frase, no en JSON', function () {
    $tarjeta = json_encode([
        'type'        => 'preorder',
        'preorder_id' => 1792531797473,
        'status'      => 'pending',
        'business'    => ['business_id' => 8, 'name' => 'Mercadito Búho'],
        'items'       => [
            ['name' => 'Aceite', 'quantity' => 1, 'price' => 8200],
            ['name' => 'Arroz', 'quantity' => 2, 'price' => 2100],
        ],
        'total' => 12400,
    ]);

    expect(ResumenDeMensaje::para($tarjeta))
        ->toBe('Te armó un pedido: 2 productos · $12.400.')
        ->not->toContain('{')
        ->not->toContain('preorder_id');
});

test('un solo producto se dice en singular', function () {
    $tarjeta = json_encode([
        'type'   => 'preorder',
        'status' => 'pending',
        'items'  => [['name' => 'Aceite', 'quantity' => 1, 'price' => 8200]],
        'total'  => 8200,
    ]);

    expect(ResumenDeMensaje::para($tarjeta))->toBe('Te armó un pedido: 1 producto · $8.200.');
});

test('sin total, se invita a abrirlo en vez de inventarse una cifra', function () {
    $tarjeta = json_encode([
        'type'   => 'preorder',
        'status' => 'pending',
        'items'  => [['name' => 'Aceite']],
    ]);

    expect(ResumenDeMensaje::para($tarjeta))
        ->toBe('Te armó un pedido: 1 producto. Ábrelo para confirmarlo.');
});

test('confirmar y cancelar el pedido se dicen con esas palabras', function () {
    expect(ResumenDeMensaje::para(json_encode(['type' => 'preorder', 'status' => 'confirmed'])))
        ->toBe('Confirmó el pedido.');

    expect(ResumenDeMensaje::para(json_encode(['type' => 'preorder_status', 'status' => 'cancelled'])))
        ->toBe('Canceló el pedido.');
});

test('una tarjeta de un tipo que todavia no conocemos no vuelca el JSON', function () {
    /*
     * El dia que alguien anada un tipo nuevo y olvide pasar por acá, el peor
     * caso tiene que ser un aviso poco específico, nunca otra vez el volcado.
     */
    $tarjeta = json_encode(['type' => 'cupon_regalo', 'codigo' => 'ABC123']);

    expect(ResumenDeMensaje::para($tarjeta))
        ->toBe('Te envió un mensaje.')
        ->not->toContain('ABC123');
});

test('un JSON roto se trata como texto, no revienta', function () {
    expect(ResumenDeMensaje::para('{"type":"preorder", esto no cierra'))
        ->toContain('{"type":"preorder"');
});

test('un mensaje vacio no deja el aviso en blanco', function () {
    expect(ResumenDeMensaje::para('   '))->toBe('Te envió un mensaje.');
    expect(ResumenDeMensaje::para(null))->toBe('Te envió un mensaje.');
});

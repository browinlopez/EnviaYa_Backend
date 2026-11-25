<?php

use App\Services\ArmadoDelPedido;

/*
 * La app manda la hora programada en ISO con zona UTC. Las 3 p. m. de
 * Colombia llegan como las 20:00Z y se guardaban así: el pedido quedaba para
 * las 8 de la noche.
 */
it('guarda la hora programada en la hora de Colombia', function () {
    config(['app.timezone' => 'America/Bogota']);

    expect(ArmadoDelPedido::aLaHoraLocal('2026-11-25T20:00:00.000Z'))
        ->toBe('2026-11-25 15:00:00');
});

it('una hora sin zona se deja como viene', function () {
    config(['app.timezone' => 'America/Bogota']);
    date_default_timezone_set('America/Bogota');

    expect(ArmadoDelPedido::aLaHoraLocal('2026-11-25 15:00:00'))
        ->toBe('2026-11-25 15:00:00');
});

it('sin fecha no inventa una', function () {
    expect(ArmadoDelPedido::aLaHoraLocal(null))->toBeNull();
});

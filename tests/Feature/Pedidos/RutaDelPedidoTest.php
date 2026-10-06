<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * LA CLAVE DE GOOGLE DEJA DE VIAJAR DENTRO DE LA APP.
 *
 * La app llamaba a Directions con la clave metida en el paquete —la misma,
 * además, que dibuja los mapas—. Una clave de servicio web NO SE PUEDE
 * restringir por aplicación: Google solo admite restringirla por IP, y una app
 * no tiene IP fija. Iba sin restricción ninguna, y cualquiera que abra el APK
 * la saca y factura a la cuenta de VeciPa'Ya.
 */
beforeEach(function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));
    config(['services.google.maps_server_key' => 'CLAVE-DE-SERVIDOR']);
    cache()->flush();
});

test('la ruta se pide con la clave del SERVIDOR, no con ninguna del telefono', function () {
    Http::fake([
        'maps.googleapis.com/*' => Http::response([
            'routes' => [['overview_polyline' => ['points' => 'abc123']]],
        ]),
    ]);

    $r = $this->getJson('/v1/rutas?origen=11.0,-74.8&destino=11.1,-74.9')->assertOk();

    expect($r->json('polilinea'))->toBe('abc123');

    Http::assertSent(function ($peticion) {
        // La clave que sale hacia Google es la del servidor.
        return str_contains($peticion->url(), 'key=CLAVE-DE-SERVIDOR');
    });
});

test('no se acepta cualquier cosa como coordenada', function () {
    /*
     * Sin esto, lo que llegue se le reenvia tal cual a Google y se factura
     * igual. Y es una ruta autenticada pero abierta a cualquier comprador.
     */
    $this->getJson('/v1/rutas?origen=hola&destino=11.1,-74.9')
        ->assertStatus(422);
});

test('sin clave configurada se dice, no se revienta', function () {
    /*
     * 503 y no 500: no es que la peticion este mal, es que falta
     * configuracion. La app lo trata como «sin ruta» y pinta la linea recta,
     * que es lo que hacia antes de que esto existiera.
     */
    config(['services.google.maps_server_key' => '']);

    $this->getJson('/v1/rutas?origen=11.0,-74.8&destino=11.1,-74.9')
        ->assertStatus(503);
});

test('la misma ruta no se paga dos veces', function () {
    /*
     * Dos domiciliarios que salen de la misma tienda al mismo barrio piden lo
     * mismo, y el seguimiento la vuelve a pedir cada vez que se reabre la
     * pantalla. Cada una de esas es una llamada facturada.
     */
    Http::fake([
        'maps.googleapis.com/*' => Http::response([
            'routes' => [['overview_polyline' => ['points' => 'xyz']]],
        ]),
    ]);

    $this->getJson('/v1/rutas?origen=11.0,-74.8&destino=11.1,-74.9')->assertOk();
    $this->getJson('/v1/rutas?origen=11.0,-74.8&destino=11.1,-74.9')->assertOk();

    Http::assertSentCount(1);
});

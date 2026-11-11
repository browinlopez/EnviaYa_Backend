<?php

use App\Exceptions\FalloDeLaPasarela;
use App\Models\Rol;
use App\Models\User;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
 * El ayudante se llama `comoCompradorDelPago` y no `comoComprador` porque ese
 * nombre ya existe en `tests/Feature/Areas/PanelViejoRetiradoTest.php`, y Pest
 * carga TODOS los archivos en el mismo proceso: el choque es un
 * `Cannot redeclare`, no un aviso. Es la misma trampa que `tests/Pest.php`
 * documenta para los ayudantes compartidos.
 */

/**
 * CONSULTAR UN PAGO: UNA REFERENCIA QUE NO EXISTE NO ES UN FALLO NUESTRO
 *
 * Lo encontró el arnés recorriendo el sistema contra la pasarela de verdad:
 * `GET /v1/bold/status/{ref}` con una referencia inventada devolvía **500 con
 * el cuerpo CRUDO de Bold dentro de `message`**:
 *
 *   {"payload":{"status_code":"PA_004","message":"Reference Error"},
 *    "errors":["Reference NO-EXISTE not found"]}
 *
 * Dos problemas en uno. Un 500 le dice al teléfono «vuelve a intentarlo», y
 * esta pantalla sondea cada pocos segundos mientras el cliente mira el QR: se
 * reintentaba una referencia que no va a aparecer nunca. Y el cuerpo de la
 * pasarela —su código interno, su texto en inglés— acababa en la pantalla de
 * alguien que compró arroz.
 *
 * Bold contesta un 404 limpio cuando la referencia no existe. Eso está
 * MEDIDO contra la pasarela de pruebas, no supuesto, y es lo que permite
 * traducirlo sin emparejar cadenas: `estado() === 404`.
 */
function comoCompradorDelPago(): void
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));
}

it('una referencia que la pasarela no conoce da 404, no 500', function () {
    comoCompradorDelPago();

    // Lo que Bold contesta de verdad, con su estado y su cuerpo.
    Http::fake(['*' => Http::response(
        '{"payload": {"status_code": "PA_004", "message": "Reference Error"},'
        .' "errors": ["Reference NO-EXISTE not found"]}', 404
    )]);

    $r = $this->getJson('/v1/bold/status/NO-EXISTE');

    $r->assertStatus(404);
    expect($r->json('message'))->toBe('No encontramos ese pago.');
});

it('no filtra el cuerpo de la pasarela hacia el telefono', function () {
    comoCompradorDelPago();

    Http::fake(['*' => Http::response(
        '{"payload": {"status_code": "PA_004", "message": "Reference Error"},'
        .' "errors": ["Reference NO-EXISTE not found"]}', 404
    )]);

    $crudo = $this->getJson('/v1/bold/status/NO-EXISTE')->content();

    /*
     * Lo que NO puede salir: el código interno de la pasarela, su texto en
     * inglés, ni la palabra que delata de dónde viene el error. Se comprueba
     * sobre la respuesta ENTERA y no sobre `message`, porque un cuerpo
     * filtrado da igual en qué clave venga.
     */
    expect($crudo)
        ->not->toContain('PA_004')
        ->not->toContain('Reference Error')
        ->not->toContain('not found')
        ->not->toContain('payload');
});

it('un fallo de la pasarela sigue siendo 500, para que el telefono reintente', function () {
    comoCompradorDelPago();

    // 503: la pasarela caída. Eso SI hay que reintentarlo.
    Http::fake(['*' => Http::response('{"errors":["service unavailable"]}', 503)]);

    $r = $this->getJson('/v1/bold/status/CUALQUIERA');

    $r->assertStatus(500);
    expect($r->json('message'))->toContain('No pudimos consultar el pago');
    expect($r->content())->not->toContain('service unavailable');
});

it('la excepcion distingue el no-existe del resto mirando el estado, no el texto', function () {
    $noExiste = FalloDeLaPasarela::desde(
        new Illuminate\Http\Client\Response(
            new Response(404, [], '{"payload":{"status_code":"PA_004"}}')
        )
    );

    expect($noExiste->noExiste())->toBeTrue();
    expect($noExiste->estado())->toBe(404);
    expect($noExiste->codigo())->toBe('PA_004');

    $caida = FalloDeLaPasarela::desde(
        new Illuminate\Http\Client\Response(
            new Response(503, [], '{"errors":["nope"]}')
        )
    );

    expect($caida->noExiste())->toBeFalse();

    /*
     * Y EL MENSAJE SIGUE SIENDO EL CUERPO. No es un descuido: `OrderController`
     * decide qué se le dice al comprador buscando `MCFG_004` y `Not Available`
     * dentro del mensaje de la excepción. Quitarlo rompería esa detección sin
     * un solo error, y el comprador recibiría un texto genérico donde antes
     * recibía el que explica que la pasarela no está disponible.
     */
    $conCodigo = FalloDeLaPasarela::desde(
        new Illuminate\Http\Client\Response(
            new Response(400, [], '{"errors":["MCFG_004 Not Available"]}')
        )
    );

    expect(str_contains($conCodigo->getMessage(), 'MCFG_004'))->toBeTrue();
    expect(str_contains($conCodigo->getMessage(), 'Not Available'))->toBeTrue();
});

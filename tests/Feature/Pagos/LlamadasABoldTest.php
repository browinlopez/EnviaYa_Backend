<?php

use App\Services\BoldService;
use Illuminate\Support\Facades\Http;

/**
 * LAS SIETE LLAMADAS A BOLD
 *
 * Ninguna prueba las ejercitaba, y eso costó caro: el commit que puso los
 * topes de tiempo sustituyó cada `Http::withHeaders($this->headers())` de la
 * clase por `$this->peticion()` —siete sitios, correcto— y también el de
 * DENTRO de `peticion()`, que era el único que tenía que quedarse. El método se
 * llamaba a sí mismo.
 *
 * Lo que dejaba no era una llamada lenta: era recursión infinita, medida como
 * `Allowed memory size of 134217728 bytes exhausted in BoldService.php on line
 * 45`. Un error FATAL, no una excepción, así que ni el `try/catch` de los
 * controladores lo atrapa, y bajo Octane se lleva al trabajador por delante.
 * Cobrar con tarjeta, con PSE o con QR era imposible.
 *
 * NO LLEGÓ A PRODUCCIÓN: el commit estaba entre los que todavía no se habían
 * subido. Lo que habría pasado al subir es que los pagos en línea dejaran de
 * funcionar enteros, con el arreglo que venía a protegerlos.
 *
 * Por eso estas pruebas son de las más simples del repositorio y de las que más
 * valen: lo único que hacen es LLAMAR a cada método. Con el código de antes, la
 * primera se cae y se lleva el proceso.
 */
function bold(): BoldService
{
    /*
     * La configuración se pone ANTES de resolver el servicio: `BoldService`
     * lee `base_url` y `api_key` en su constructor, así que un `config()`
     * posterior no le llega.
     */
    config([
        'services.bold.base_url' => 'https://api.bold.test',
        'services.bold.api_key' => 'clave-de-prueba',
    ]);

    return app(BoldService::class);
}

it('las siete llamadas completan y van a donde tienen que ir', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $bold = bold();

    // Si alguna de estas siete entra en recursión, la prueba no falla: se
    // lleva el proceso entero. Llegar a las comprobaciones de abajo YA es el
    // resultado.
    $bold->createIntent(['amount' => 1000]);
    $bold->makePayment(['token' => 'tok']);
    $bold->anular('TX-1');
    $bold->devolver('REF-1', 'TX-1', 'el cliente cancelo');
    $bold->estadoDeDevolucion('TX-1');
    $bold->bancosPse();
    $bold->checkPayment('REF-1');

    $pedidas = [];

    Http::assertSentCount(7);

    Http::assertSent(function ($request) use (&$pedidas) {
        $pedidas[] = $request->method().' '.$request->url();

        return true;
    });

    expect($pedidas)->toBe([
        'POST https://api.bold.test/v1/payment-intent',
        'POST https://api.bold.test/v1/payment',
        'POST https://api.bold.test/v1/payment/void',
        'POST https://api.bold.test/v1/payment/refund',
        'GET https://api.bold.test/v1/payment/refund/TX-1',
        'GET https://api.bold.test/v1/payment/pse/banks',
        'GET https://api.bold.test/v1/payment/REF-1',
    ]);
});

it('todas se identifican con la clave de la pasarela', function () {
    /*
     * `headers()` se quedó huérfano cuando `peticion()` dejó de llamarlo, y un
     * método privado sin usar es justo lo que alguien borra «limpiando». Si
     * desaparece, Bold contesta 401 a todo y el síntoma —pagos rechazados— no
     * apunta a un encabezado.
     */
    Http::fake(['*' => Http::response([], 200)]);

    bold()->bancosPse();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'x-api-key clave-de-prueba')
        && $request->hasHeader('Accept', 'application/json'));
});

it('un fallo de la pasarela se propaga con el cuerpo que contesto', function () {
    /*
     * El contrato de error de esta clase: lanza con el cuerpo crudo de Bold.
     * No se cambia —`OrderController` y `PaymentController` lo traducen— pero
     * se fija, porque es lo que hace que un pago rechazado se pueda explicar.
     */
    Http::fake(['*' => Http::response('{"errors":["tarjeta vencida"]}', 422)]);

    expect(fn () => bold()->makePayment(['token' => 'tok']))
        ->toThrow(Exception::class, '{"errors":["tarjeta vencida"]}');
});

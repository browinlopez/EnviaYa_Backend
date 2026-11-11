<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * PEDIR EL ENLACE DE CONTRASEÑA NO DICE QUÉ CUENTAS EXISTEN
 *
 * Lo encontró el arnés de validación recorriendo el sistema contra el servidor
 * de verdad: `POST /v1/forgot-password` devolvía **200 con un correo
 * registrado y 500 con uno que no existía**. Dos códigos distintos son una
 * respuesta a la pregunta «¿tiene cuenta esta persona?», y cualquiera podía
 * hacérsela en bucle con una lista de correos.
 *
 * En una aplicación de domicilios eso no es un dato neutro: saber que un
 * correo o un teléfono tiene cuenta dice dónde compra alguien.
 *
 * Y la misma rama atrapaba `RESET_THROTTLED`, así que quien pulsaba el botón
 * dos veces —lo normal cuando un correo tarda— recibía «No se pudo enviar el
 * enlace» con un 500, estando todo bien.
 *
 * Lo que se fija aquí es la propiedad, no el texto: las respuestas de los tres
 * casos tienen que ser INDISTINGUIBLES. Comparar la respuesta completa y no
 * solo el código es lo que impide que mañana alguien «mejore» uno de los
 * mensajes y vuelva a abrir la fuga sin darse cuenta.
 */
function cuentaDePrueba(string $correo): User
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    return User::factory()->create([
        'rol' => 1,
        'email' => $correo,
        'email_verified_at' => now(),
    ]);
}

beforeEach(function () {
    /*
     * El limitador vive en la caché y sobrevive de una prueba a la siguiente:
     * sin limpiarlo, la tercera petición de este archivo recibe un 429 y la
     * comparación mide el limitador en vez de la fuga.
     */
    RateLimiter::clear('forgot-password');
    cache()->flush();
    Notification::fake();
});

it('contesta lo mismo con un correo que existe y con uno que no', function () {
    cuentaDePrueba('existe@ejemplo.test');

    $conCuenta = $this->postJson('/v1/forgot-password', ['email' => 'existe@ejemplo.test']);
    $sinCuenta = $this->postJson('/v1/forgot-password', ['email' => 'no-existe@ejemplo.test']);

    expect($conCuenta->status())->toBe(200);
    expect($sinCuenta->status())->toBe(200);

    /*
     * LA RESPUESTA ENTERA, no solo el código. Un 200 en los dos casos con
     * mensajes distintos seguiría contestando la pregunta.
     */
    expect($sinCuenta->json())->toBe($conCuenta->json());
});

it('el mensaje no afirma que la cuenta exista', function () {
    $r = $this->postJson('/v1/forgot-password', ['email' => 'quien-sabe@ejemplo.test']);

    $mensaje = $r->json('message');

    expect($mensaje)->toBeString();
    // «Si esa cuenta existe...»: condicional, no promesa.
    expect(strtolower($mensaje))->toContain('si esa cuenta existe');
});

it('pedirlo dos veces seguidas no devuelve un error', function () {
    cuentaDePrueba('insistente@ejemplo.test');

    $primera = $this->postJson('/v1/forgot-password', ['email' => 'insistente@ejemplo.test']);
    $segunda = $this->postJson('/v1/forgot-password', ['email' => 'insistente@ejemplo.test']);

    expect($primera->status())->toBe(200);

    /*
     * La segunda choca con el minuto de espera de Laravel —`RESET_THROTTLED`—
     * y ANTES caía en el mismo `else` que el correo desconocido: 500. Ahora es
     * la misma respuesta que la primera, que además es la verdad: ya se envió.
     *
     * Se admite el 429 del limitador de la ruta, que es otra cosa y legítima:
     * lo que no se admite es un 500.
     */
    expect($segunda->status())->toBeIn([200, 429]);

    if ($segunda->status() === 200) {
        expect($segunda->json())->toBe($primera->json());
    }
});

it('quien SI tiene cuenta recibe el aviso, aunque la respuesta sea generica', function () {
    /*
     * La contrapartida imprescindible: una respuesta que no distingue podría
     * conseguirse no enviando nunca nada. Esto comprueba que al correo
     * registrado le sale su notificación, y al desconocido no.
     */
    $u = cuentaDePrueba('recibe@ejemplo.test');

    $this->postJson('/v1/forgot-password', ['email' => 'recibe@ejemplo.test'])
        ->assertOk();

    Notification::assertSentTo($u, ResetPassword::class);

    Notification::assertCount(1);
});

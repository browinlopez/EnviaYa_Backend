<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

/*
 * LAS TRES SE SALTAN, Y EL MOTIVO QUE DECÍA ANTES ERA FALSO.
 *
 * Decía «verification.verify no existe». SÍ EXISTE: está en
 * `GET laravel-verify-email/{id}/{hash}` → `Auth\VerifyEmailController`, el
 * andamiaje de Breeze, vivo. Lo que no existen son las RUTAS POR DEFECTO que
 * estas pruebas usan —`/verify-email` y `route('dashboard')`—, porque este
 * proyecto las renombró. Comprobado el 13 de noviembre de 2026 quitándoles el
 * salto: las tres fallan, y la primera con un 302 donde espera un 200.
 *
 * La diferencia importa: un motivo falso manda al siguiente a buscar una ruta
 * que sí está, y de paso esconde que en este backend hay DOS mecanismos de
 * verificación de correo a la vez —el de Breeze por URL firmada y el propio
 * por `email_verification_token`—. Eso es una decisión pendiente, no un
 * descuido de estas pruebas.
 *
 * Y NO SE OMITEN «HASTA QUE SE ESCRIBAN TESTS DEL FLUJO REAL», como decía: ya
 * están escritos, en cuatro archivos.
 *
 *   ReenviarVerificacionWebTest   el enlace vencido, pedir uno nuevo, que no
 *                                 revele quién tiene cuenta
 *   VerificarDesdeElPanelTest     que el equipo dé por bueno un correo, y que
 *                                 se distinga de quien abrió su propio enlace
 *   RegistroResistenteTest        el alta cuando el correo no sale
 *   DominioPropioTest             el enlace que se manda
 *
 * Así que lo que queda aquí es andamiaje de Breeze contra rutas de Breeze.
 * Reescribirlas contra las de este proyecto sería repetir esa cobertura; se
 * dejan saltadas, con el motivo correcto, para que su borrado sea una
 * decisión y no un descuido.
 */

test('email verification screen can be rendered', function () {
    $this->markTestSkipped('Andamiaje de Breeze: `/verify-email` no es una ruta de este proyecto. Ver la cabecera.');

    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/verify-email');

    $response->assertStatus(200);
});

test('email can be verified', function () {
    $this->markTestSkipped('Andamiaje de Breeze contra sus rutas por defecto, no las de este proyecto. Ver la cabecera.');

    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $this->markTestSkipped('Andamiaje de Breeze contra sus rutas por defecto, no las de este proyecto. Ver la cabecera.');

    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')]
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

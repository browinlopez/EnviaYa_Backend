<?php

use App\Mail\VerifyEmailCustomMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * PEDIR UN ENLACE NUEVO DESDE LA PÁGINA DE ERROR.
 *
 * Los enlaces que salieron con el dominio viejo, los vencidos y los de un
 * correo anterior terminan en «No pudimos verificar tu correo». Esa página
 * solo ofrecía volver al sitio: la persona tenía que adivinar que debía ir a
 * la app e intentar entrar. Ahora pide el correo y manda un enlace nuevo ahí
 * mismo, y con ese enlace la cuenta se verifica.
 */

function pendienteDeVerificar(array $datos = []): User
{
    return User::factory()->unverified()->create(array_merge([
        'email'                         => 'vecino@ejemplo.com',
        'email_verification_token'      => Str::random(60),
        'email_verification_expires_at' => now()->subHours(2),
    ], $datos));
}

test('la página de error ofrece pedir un enlace nuevo', function () {
    $this->get('/verify-email?token=no-existe')
        ->assertOk()
        ->assertSee('Enviarme un enlace nuevo')
        ->assertSee(route('verify.email.reenviar'), false);
});

test('con un enlace vencido, el formulario ya trae el correo', function () {
    $user = pendienteDeVerificar();

    $this->get('/verify-email?token=' . $user->email_verification_token)
        ->assertOk()
        ->assertSee('caducó')
        ->assertSee('value="vecino@ejemplo.com"', false);
});

test('pedirlo manda un enlace nuevo, y ese enlace verifica la cuenta', function () {
    Mail::fake();
    $user = pendienteDeVerificar();
    $vencido = $user->email_verification_token;

    $this->post('/verify-email/reenviar', ['email' => 'vecino@ejemplo.com'])
        ->assertOk()
        ->assertSee('Revisa tu correo');

    $user->refresh();
    expect($user->email_verification_token)->not->toBe($vencido)
        ->and($user->email_verification_expires_at->isFuture())->toBeTrue();

    Mail::assertSent(VerifyEmailCustomMail::class, 1);

    $this->get('/verify-email?token=' . $user->email_verification_token)
        ->assertOk()
        ->assertSee('Correo verificado');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('si el enlace sigue vigente se reenvía el mismo, para que sirva cualquiera de los dos correos', function () {
    Mail::fake();
    $user = pendienteDeVerificar(['email_verification_expires_at' => now()->addMinutes(40)]);
    $vigente = $user->email_verification_token;

    $this->post('/verify-email/reenviar', ['email' => 'vecino@ejemplo.com'])->assertOk();

    expect($user->fresh()->email_verification_token)->toBe($vigente);
    Mail::assertSent(VerifyEmailCustomMail::class, 1);
});

test('no revela quién tiene cuenta: misma página y ningún correo', function () {
    Mail::fake();
    User::factory()->create(['email' => 'ya.verificado@ejemplo.com']);

    foreach (['nadie@ejemplo.com', 'ya.verificado@ejemplo.com'] as $correo) {
        $this->post('/verify-email/reenviar', ['email' => $correo])
            ->assertOk()
            ->assertSee('Revisa tu correo');
    }

    Mail::assertNothingSent();
});

test('si el correo no sale, lo dice en vez de fingir que se envió', function () {
    $user = pendienteDeVerificar();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $this->post('/verify-email/reenviar', ['email' => $user->email])
        ->assertOk()
        ->assertSee('No pudimos enviar el correo');
});

test('un correo mal escrito no manda nada', function () {
    Mail::fake();

    $this->post('/verify-email/reenviar', ['email' => 'esto-no-es-un-correo'])
        ->assertSessionHasErrors('email');

    Mail::assertNothingSent();
});

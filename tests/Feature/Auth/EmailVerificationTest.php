<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

// Estos tests prueban el flujo estándar de Breeze (ruta firmada
// verification.verify), pero este proyecto usa un flujo propio con
// email_verification_token y correo custom (AuthService::verify).
// Se omiten hasta que se escriban tests del flujo real.

test('email verification screen can be rendered', function () {
    $this->markTestSkipped('Flujo de verificación custom por token; la pantalla de Breeze no aplica.');

    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/verify-email');

    $response->assertStatus(200);
});

test('email can be verified', function () {
    $this->markTestSkipped('Flujo de verificación custom por token; verification.verify no existe.');

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
    $this->markTestSkipped('Flujo de verificación custom por token; verification.verify no existe.');

    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')]
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

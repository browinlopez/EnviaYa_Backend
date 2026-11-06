<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * CAMBIAR EL CORREO PIDE LA CONTRASEÑA; el resto del perfil, no.
 */
function usuarioConClave(): User
{
    return User::factory()->create(['email' => 'yo@ejemplo.com', 'password' => Hash::make('clave-segura-1')]);
}

test('nombre y teléfono se cambian sin contraseña, mandando el mismo correo', function () {
    $u = usuarioConClave();
    Sanctum::actingAs($u);

    $this->putJson('/v1/users/update', ['name' => 'Nuevo Nombre', 'email' => 'YO@ejemplo.com', 'phone' => '3001234567'])
        ->assertOk();

    expect($u->fresh()->name)->toBe('Nuevo Nombre');
});

test('cambiar el correo sin contraseña, o con una mala, no pasa', function () {
    $u = usuarioConClave();
    Sanctum::actingAs($u);

    $this->putJson('/v1/users/update', ['email' => 'otro@ejemplo.com'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->putJson('/v1/users/update', ['email' => 'otro@ejemplo.com', 'current_password' => 'mala'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect($u->fresh()->email)->toBe('yo@ejemplo.com');
});

test('con la contraseña correcta el correo sí cambia', function () {
    $u = usuarioConClave();
    Sanctum::actingAs($u);

    $this->putJson('/v1/users/update', ['email' => 'otro@ejemplo.com', 'current_password' => 'clave-segura-1'])
        ->assertOk();

    expect($u->fresh()->email)->toBe('otro@ejemplo.com');
});

test('mandar el correo vacio no lo borra', function () {
    $u = \App\Models\User::factory()->create(['email' => 'sigue@prueba.test']);
    \Laravel\Sanctum\Sanctum::actingAs($u);

    $this->putJson('/v1/users/update', ['name' => 'Nuevo nombre', 'email' => ''])->assertOk();

    expect($u->fresh()->email)->toBe('sigue@prueba.test')
        ->and($u->fresh()->name)->toBe('Nuevo nombre');
});

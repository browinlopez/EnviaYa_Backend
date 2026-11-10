<?php

/**
 * LA ENTRADA DE SIETE ENDPOINTS DE CUENTA Y RESEÑAS.
 *
 * Mismo acuerdo: primero se fija qué acepta cada uno, después se extrae la
 * regla. Recuperar la contraseña, confirmarla con el código, reenviar la
 * verificación del correo (desde la app y desde la web), verificarlo, y los
 * dos listados de reseñas.
 *
 * **Son rutas públicas**: cualquiera con la dirección las llama. Lo que las
 * protege es el limitador de peticiones y que el token sea bueno, no un
 * permiso — razón de más para que lo que aceptan esté fijado.
 */

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function rolesBasicos(): void
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }
}

/* ------------------------------------------------------------------ */
/* RECUPERAR LA CONTRASEÑA                                             */
/* ------------------------------------------------------------------ */

test('pedir el codigo de recuperacion exige un correo con forma de correo', function () {
    rolesBasicos();

    $this->postJson('/v1/forgot-password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->postJson('/v1/forgot-password', ['email' => 'esto-no-es-un-correo'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('confirmar la contrasena exige token, correo y una clave confirmada', function () {
    rolesBasicos();

    $this->postJson('/v1/reset-password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token', 'email', 'password']);
});

test('una contrasena nueva sin confirmar no pasa', function () {
    // `confirmed` existe para que un error de dedo no deje a alguien fuera de
    // su propia cuenta, justo cuando viene de recuperarla.
    rolesBasicos();

    $this->postJson('/v1/reset-password', [
        'token' => '123456',
        'email' => 'alguien@ejemplo.com',
        'password' => 'claveNueva123',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});

test('una contrasena de menos de seis caracteres no pasa', function () {
    rolesBasicos();

    $this->postJson('/v1/reset-password', [
        'token' => '123456',
        'email' => 'alguien@ejemplo.com',
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});

/* ------------------------------------------------------------------ */
/* VERIFICAR EL CORREO                                                 */
/* ------------------------------------------------------------------ */

test('reenviar la verificacion exige un correo', function () {
    rolesBasicos();

    $this->postJson('/v1/email/resend-verification', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('verificar el correo exige el token', function () {
    rolesBasicos();

    $this->getJson('/verify-email')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token']);
});

/* ------------------------------------------------------------------ */
/* RESEÑAS                                                             */
/* ------------------------------------------------------------------ */

test('las resenas de un negocio exigen un negocio que exista', function () {
    rolesBasicos();

    $this->postJson('/v1/reviews/business/by', ['business_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['business_id']);
});

test('las resenas de un domiciliario exigen uno que exista', function () {
    rolesBasicos();
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $this->postJson('/v1/reviews/domiciliary/by', ['domiciliary_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['domiciliary_id']);
});

test('un negocio que si existe no da error de validacion', function () {
    // La contraparte: si solo se probara el rechazo, una regla que rechazara
    // TODO pasaría la prueba igual.
    rolesBasicos();

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $this->postJson('/v1/reviews/business/by', ['business_id' => $negocio])
        ->assertJsonMissingPath('errors.business_id');
});

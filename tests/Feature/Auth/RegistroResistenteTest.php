<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * EL REGISTRO NO SE CAE.
 *
 * Es la primera pantalla que toca cualquiera: si falla, esa persona no llega a
 * ver nada más de la plataforma. Los dos fallos que se fijan acá estaban EN
 * PRODUCCIÓN y se encontraron intentando crear una cuenta de verdad contra
 * `api.vecipaya.com`, que respondió «Server Error».
 */

beforeEach(function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
});

test('registrarse sin decir si vive en un conjunto no revienta', function () {
    Mail::fake();

    /*
     * `belongs_to_complex` se valida como `boolean`, NO como `required`. Si el
     * cliente no lo manda, la clave no existe en `$validated` y leerla directo
     * lanzaba «Undefined array key»: un 500 en el registro.
     *
     * La app siempre lo manda porque tiene un interruptor, así que el fallo
     * estaba latente esperando a cualquier otro cliente.
     */
    $r = $this->postJson('/v1/register', [
        'name'     => 'Sin Bandera',
        'email'    => 'sin.bandera@ejemplo.com',
        'password' => '12345678',
    ]);

    $r->assertStatus(201);

    $user = User::where('email', 'sin.bandera@ejemplo.com')->first();

    expect($user)->not->toBeNull();

    // Y queda como lo que es: alguien que no declaró conjunto.
    expect((int) DB::table('buyer')->where('user_id', $user->user_id)->value('belongs_to_complex'))
        ->toBe(0);
});

test('si el correo de verificacion falla, la cuenta igual queda creada', function () {
    /*
     * EL ENVÍO ESTABA DENTRO DE LA TRANSACCIÓN.
     *
     * Un tropiezo del SMTP —o los cuatro segundos que tarda Gmail en una
     * conexión mala— hacía rodar atrás el registro entero y devolvía un 500.
     * La persona se quedaba sin cuenta por algo que no tiene nada que ver con
     * crearla, y al reintentar con el mismo correo recibía «ya está
     * registrado» sobre una cuenta que no existía.
     */
    Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));

    $r = $this->postJson('/v1/register', [
        'name'     => 'Correo Caido',
        'email'    => 'correo.caido@ejemplo.com',
        'password' => '12345678',
        'belongs_to_complex' => false,
    ]);

    // 201: la cuenta se creó. Lo que falló fue el aviso, y se dice.
    $r->assertStatus(201);

    expect($r->json('email_enviado'))->toBeFalse()
        ->and(User::where('email', 'correo.caido@ejemplo.com')->exists())->toBeTrue();
});

test('el registro normal manda el correo y lo dice', function () {
    Mail::fake();

    $r = $this->postJson('/v1/register', [
        'name'     => 'Con Correo',
        'email'    => 'con.correo@ejemplo.com',
        'password' => '12345678',
        'belongs_to_complex' => false,
    ]);

    $r->assertStatus(201);

    // `email_enviado` no es decoración: la app lo usa para decidir si enseña
    // «revisa tu correo» o «pide que te lo reenviemos».
    expect($r->json('email_enviado'))->toBeTrue();
});

test('el correo repetido sigue respondiendo que ya existe', function () {
    Mail::fake();

    $this->postJson('/v1/register', [
        'name' => 'Primero', 'email' => 'repetido@ejemplo.com',
        'password' => '12345678', 'belongs_to_complex' => false,
    ])->assertStatus(201);

    /*
     * Sacar el envío de la transacción no puede haber roto esto: la unicidad
     * la sigue cuidando la base, y el segundo intento tiene que distinguirse
     * de un error cualquiera.
     */
    $r = $this->postJson('/v1/register', [
        'name' => 'Segundo', 'email' => 'repetido@ejemplo.com',
        'password' => '12345678', 'belongs_to_complex' => false,
    ]);

    expect($r->status())->toBeIn([409, 422]);
    expect(User::where('email', 'repetido@ejemplo.com')->count())->toBe(1);
});

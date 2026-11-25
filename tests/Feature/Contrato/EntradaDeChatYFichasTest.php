<?php

/**
 * LA ENTRADA DE ONCE ENDPOINTS MÁS, ANTES DE TOCARLOS.
 *
 * Mismo acuerdo que en `EntradaDelPanelYPedidosTest`: ninguno tenía una
 * prueba que lo llamara, así que primero se fija qué acepta y después se
 * extrae la regla a un `FormRequest`.
 *
 * Chat, fichas de domiciliario, afiliación y favoritos, contraseña y los dos
 * listados de productos. Lo que se prueba es la ENTRADA, no lo que hace cada
 * uno: campos obligatorios, ids que no existen y la forma del error.
 */

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/** Una cuenta cualquiera con sesión abierta. */
function alguienConSesion(int $rol = 1): User
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }

    $u = User::factory()->create(['rol' => $rol]);
    Sanctum::actingAs($u);

    return $u;
}

/* ------------------------------------------------------------------ */
/* CHAT */
/* ------------------------------------------------------------------ */

test('pedir los mensajes tiene DOS entradas, no una', function () {
    /*
     * Esto lo descubrió la prueba al escribirla, y conviene que quede fijo:
     * `getMessages` acepta dos formas y valida distinto en cada una.
     *
     *   · con `chat_id`: se exige que ese chat exista;
     *   · sin él: se exigen las DOS personas, porque entonces el chat se
     *     busca —o se abre— por la pareja.
     *
     * Las dos formas caben en un solo `FormRequest`
     * (`MensajesDelChatRequest`): `required_without:chat_id` exige las
     * personas solo cuando no viene el chat. Lo que esta prueba cuida es que
     * los errores sigan saliendo en los mismos campos que antes.
     */
    alguienConSesion();

    $this->postJson('/v1/chats/messages', ['chat_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['chat_id']);

    $this->postJson('/v1/chats/messages', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['user_id', 'recipient_id']);
});

test('listar los chats de alguien exige ese alguien', function () {
    alguienConSesion();

    $this->postJson('/v1/chats/user-chats', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['user_id']);
});

test('marcar un mensaje exige el mensaje y un contenido en arreglo', function () {
    // `array` y no `string`: lo que se manda es el estado del mensaje, no su
    // texto. Un string aquí escribiría una cosa donde se espera otra.
    alguienConSesion();

    $this->postJson('/v1/chats/updateMessage', ['message_id' => 999999, 'content' => 'leido'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['message_id', 'content']);
});

/* ------------------------------------------------------------------ */
/* DOMICILIARIOS */
/* ------------------------------------------------------------------ */

test('listar los domiciliarios de un negocio exige el negocio', function () {
    alguienConSesion(2);

    $this->getJson('/v1/domiciliaries/listDomiciliariesByBusiness')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['busines_id']);
});

test('ver un domiciliario exige uno que exista', function () {
    alguienConSesion(2);

    $this->postJson('/v1/domiciliaries/showDomiciliary', ['domiciliary_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['domiciliary_id']);
});

test('corregir un domiciliario no choca contra su propio correo', function () {
    /*
     * El `unique` se ignora a sí mismo. Sin eso, guardar la ficha sin tocar
     * el correo —que es lo que hace el panel al cambiar el teléfono— chocaba
     * contra la propia fila y respondía que el correo ya existe.
     */
    alguienConSesion(2);

    $suyo = User::factory()->create(['rol' => 3, 'email' => 'repartidor@ejemplo.com']);
    DB::table('domiciliary')->insert([
        'user_id' => $suyo->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1,
    ]);

    $this->postJson('/v1/domiciliaries/updateDomiciliary', [
        'user_id' => $suyo->user_id,
        'email' => 'repartidor@ejemplo.com',
        'phone' => '3001234567',
    ])->assertJsonMissingPath('errors.email');
});

test('pero el correo de OTRA cuenta si choca', function () {
    alguienConSesion(2);

    $suyo = User::factory()->create(['rol' => 3, 'email' => 'repartidor@ejemplo.com']);
    DB::table('domiciliary')->insert([
        'user_id' => $suyo->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1,
    ]);
    $otro = User::factory()->create(['rol' => 1, 'email' => 'ocupado@ejemplo.com']);

    $this->postJson('/v1/domiciliaries/updateDomiciliary', [
        'user_id' => $suyo->user_id,
        'email' => $otro->email,
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

/* ------------------------------------------------------------------ */
/* AFILIACIÓN Y FAVORITOS */
/* ------------------------------------------------------------------ */

test('afiliar y marcar favorito exigen persona y negocio que existan', function () {
    alguienConSesion();

    foreach (['/v1/businesses/affiliations/toggleBusinesses', '/v1/favorites/toggle'] as $ruta) {
        $this->postJson($ruta, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'busines_id']);

        $this->postJson($ruta, ['user_id' => 999999, 'busines_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'busines_id']);
    }
});

/* ------------------------------------------------------------------ */
/* CONTRASEÑA */
/* ------------------------------------------------------------------ */

test('cambiar la contrasena exige la actual y una nueva confirmada', function () {
    alguienConSesion();

    $this->putJson('/v1/users/password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['current_password', 'password']);
});

test('una contrasena nueva sin confirmar no pasa', function () {
    // `confirmed` existe para que un error de dedo no deje a alguien fuera
    // de su propia cuenta.
    alguienConSesion();

    $this->putJson('/v1/users/password', [
        'current_password' => 'loquesea',
        'password' => 'unaClaveLarga123',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});

test('una contrasena de menos de ocho caracteres no pasa', function () {
    alguienConSesion();

    $this->putJson('/v1/users/password', [
        'current_password' => 'loquesea',
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});

/* ------------------------------------------------------------------ */
/* LISTADOS DE PRODUCTOS */
/* ------------------------------------------------------------------ */

test('los mas vendidos y el esquema exigen un negocio que exista', function () {
    alguienConSesion(2);

    $this->postJson('/v1/product/topProductsBusiness', ['business_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['business_id']);

    $this->getJson('/v1/product/schema?business_id=999999')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['business_id']);
});

test('el tope de los mas vendidos no se puede pedir sin limite', function () {
    // Sin tope, un `limit=100000` recorre el catálogo entero para pintar una
    // lista que nadie lee.
    alguienConSesion(2);

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $this->postJson('/v1/product/topProductsBusiness', [
        'business_id' => $negocio, 'limit' => 100000,
    ])->assertStatus(422)->assertJsonValidationErrors(['limit']);
});

<?php

declare(strict_types=1);

use App\Models\Rol;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * EL LISTADO DEL PERSONAL NO SE SIRVE A CUALQUIERA CON SESIÓN.
 *
 * `GET /v1/users` devolvía `User::where('rol', 4)->get()` —el personal— con
 * nombre, CORREO, teléfono y dirección de cada uno, dentro de un grupo cuyo
 * único guardia es `auth:sanctum`. Medido contra la respuesta real, un
 * COMPRADOR recibía 200 y dieciséis filas.
 *
 * Y no la llamaba nadie, lo que es lo que permitió retirarla sin tocar lo que
 * recibe ninguna app publicada. Dos buscadores independientes, cada uno con su
 * grupo de control:
 *
 *   scripts/rutas_sin_llamada_http.py   no aparece como camino en ninguno de
 *                                       los cuatro clientes
 *   scripts/rutas_sin_llamante.py       no aparece en ninguna versión
 *                                       publicada —1.0.0 a 1.0.12— ni en los
 *                                       202 commits de la historia de la app
 *
 * POR QUÉ ESTE ARCHIVO EXISTE SI LA SUPERFICIE YA ESTÁ CONGELADA.
 * `SuperficieDeLaApiTest` avisa de que la ruta cambió, pero no dice por qué no
 * debe volver: quien regenere el congelado con `CONGELAR_SUPERFICIE=1` la
 * readmite en silencio. Esto nombra el motivo.
 *
 * LAS TRES PRUEBAS SON UNA SOLA COSA, y la del medio es el grupo de control:
 * un 404 no prueba nada por sí mismo, porque una base mal puesta o la sesión
 * rota dan 404 en todo. Así que se comprueba que la MISMA sesión sí llega a
 * una ruta que existe. Sin eso, este archivo pasaría en verde estando ciego.
 */
function unCompradorSinModulos(): User
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'rol1', 'guard_name' => 'web']);

    return User::factory()->create(['rol' => 1]);
}

it('un comprador con sesion ya no puede listar el personal', function () {
    Sanctum::actingAs(unCompradorSinModulos());

    $r = $this->getJson('/v1/users');

    expect($r->status())->toBe(404);

    /* Y lo que importa de verdad: que no salga ni un correo. Si algún día
       alguien la devuelve con otro verbo o con otro nombre, la forma del
       cuerpo es lo que delata la fuga, no el código. */
    expect($r->getContent())->not->toContain('@');
});

it('CONTROL: esa misma sesion si llega a una ruta que existe', function () {
    Sanctum::actingAs(unCompradorSinModulos());

    /* `GET /v1/profile` está en el mismo grupo `auth:sanctum` del que se quitó
       `GET /v1/users`. Si esto diera 404, el 404 de arriba no significaría
       «la ruta no está» sino «este archivo no sabe llamar a la API». */
    $this->getJson('/v1/profile')->assertStatus(200);
});

it('el listado que si existe sigue pidiendo el modulo de usuarios', function () {
    Sanctum::actingAs(unCompradorSinModulos());

    /* Lo que los paneles usan es `GET /v1/admin/users`, detrás de
       `modulo:usuarios`. Retirar la ruta de más no debe haber abierto esta:
       un comprador no entra. */
    $r = $this->getJson('/v1/admin/users');

    expect($r->status())->toBeIn([401, 403]);
    expect($r->getContent())->not->toContain('@');
});

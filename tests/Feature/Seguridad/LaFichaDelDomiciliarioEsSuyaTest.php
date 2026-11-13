<?php

declare(strict_types=1);

use App\Http\Resources\Domiciliario\MiFichaDeDomiciliarioResource;
use App\Models\Business;
use App\Models\Domiciliary;
use App\Models\Rol;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * `POST /v1/domiciliaries/showDomiciliary` YA NO SIRVE LA FICHA A CUALQUIERA.
 *
 * ESTE ARCHIVO SUSTITUYE A `LaCedulaDelDomiciliarioLaVeCualquieraTest`, que
 * medía la fuga y estaba escrito para ponerse rojo el día que se arreglara.
 * Ese día fue el 13 de noviembre de 2026. Lo que medía queda acá como
 * historia, porque sin ella estas pruebas parecen precauciones de más:
 *
 *   el id llegaba EN EL CUERPO y nada lo comparaba con quien preguntaba, y
 *   del grupo `domiciliaries` solo cuelga `auth:sanctum`. Un COMPRADOR con
 *   sesión recibía 200 y 12 claves, entre ellas:
 *
 *     document                      la cédula
 *     user.email, user.phone        el contacto
 *     user.email_verification_token CON LO QUE SE VERIFICA SU CORREO
 *     user.policy_ip                la IP desde donde aceptó la política
 *
 * EL TOKEN ERA LO PEOR Y ERA LO QUE MENOS SE VEÍA. `UsuarioResource` lo
 * conserva a propósito y lo tiene escrito: «en cinco de los seis sitios el
 * usuario es EL PROPIO, así que su token y su IP no son una fuga: son sus
 * datos». Esa decisión se apoya en una premisa —que el usuario es quien
 * pregunta— y esta ruta era justo donde la premisa no se cumplía. Por eso el
 * arreglo es la AUTORIZACIÓN y no quitar campos: lo que estaba roto era la
 * premisa, no la lista.
 *
 * Y NO ERA UNA FUGA DE DISEÑO, ERA UN OLVIDO CON FECHA. `updateDomiciliary`,
 * en el mismo archivo, ya se había acotado por este mismo motivo —su
 * comentario lo dice— y la lectura se quedó fuera. Arreglar la escritura y
 * olvidar la lectura deja la misma puerta abierta en modo consulta. Las dos
 * usan ahora el mismo predicado, y se llama `puedeVerYEditar…` para que se
 * note cuando aparezca un tercer método.
 */
function unRolDeCada(): void
{
    foreach ([1, 2, 3] as $id) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => 'rol'.$id, 'guard_name' => 'web']);
    }
}

/** @return array{0: Domiciliary, 1: User} */
function unDomiciliarioYSuUsuario(string $cedula = '1020304050'): array
{
    unRolDeCada();

    $u = User::factory()->create([
        'rol' => 3,
        'email_verification_token' => 'TOKEN-QUE-NO-DEBE-SALIR',
    ]);

    $d = Domiciliary::create([
        'user_id' => $u->user_id,
        'available' => 1,
        'document' => $cedula,
        'qualification' => 4.6,
        'state' => 1,
    ]);

    return [$d, $u];
}

it('un comprador con sesion ya NO puede leer la ficha de un domiciliario', function () {
    [$d] = unDomiciliarioYSuUsuario('1020304050');

    unRolDeCada();
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $r = $this->postJson('/v1/domiciliaries/showDomiciliary', [
        'domiciliary_id' => $d->domiciliary_id,
    ]);

    expect($r->status())->toBe(403);

    /* Y lo que importa de verdad: que no salga ni la cédula ni el token. Se
       comprueba contra el CONTENIDO y no contra una clave, porque si algún
       día esto se devuelve con otra forma, lo que delata la fuga es que el
       número esté, no cómo se llame la clave. */
    expect($r->getContent())->not->toContain('1020304050');
    expect($r->getContent())->not->toContain('TOKEN-QUE-NO-DEBE-SALIR');
});

it('el domiciliario SI lee la suya, y sin mandar el identificador', function () {
    [$d, $u] = unDomiciliarioYSuUsuario();

    Sanctum::actingAs($u);

    /*
     * Sin cuerpo. Esto es lo que hace el arreglo seguro de verdad y no solo
     * comprobado: el servidor resuelve de la sesión, así que el cliente no
     * manda un número que pueda ser de otro. La app ya no lo manda.
     */
    $r = $this->postJson('/v1/domiciliaries/showDomiciliary', []);

    expect($r->status())->toBe(200);
    expect($r->json('domiciliary_id'))->toBe($d->domiciliary_id);
});

it('el domiciliario sigue pudiendo mandar SU identificador, como la app vieja', function () {
    [$d, $u] = unDomiciliarioYSuUsuario();

    Sanctum::actingAs($u);

    /* Las versiones publicadas lo mandan. Para ellas no cambia nada: el id
       que mandan es el mismo que resolvería la sesión. */
    $r = $this->postJson('/v1/domiciliaries/showDomiciliary', [
        'domiciliary_id' => $d->domiciliary_id,
    ]);

    expect($r->status())->toBe(200);
    expect($r->json('document'))->toBe('1020304050');
});

/**
 * Un tendero con su tienda, al estilo de `AltaDeDomiciliarioTest`: por
 * `DB::table`, porque `business.type` apunta a `category_business` y
 * `municipality_id` es nulable. La primera versión de estas dos pruebas puso
 * `municipality_id => 1` a mano y las dos reventaron con una violación de
 * clave ajena.
 *
 * EL NOMBRE LLEVA SUFIJO porque `unTenderoConTienda` ya existe en
 * `CambioDeRolTest`. Las funciones de Pest son globales, asi que dos
 * archivos con el mismo nombre revientan la suite con «Cannot redeclare»
 * --y solo lo ve la suite COMPLETA: cada archivo por separado pasa en
 * verde. Es la tercera vez en esta sesion con el mismo molde.
 *
 * @return array{0: User, 1: int}
 */
function unTenderoConTiendaParaLaFicha(string $nombre): array
{
    unRolDeCada();

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(
        ['user_id' => $tendero->user_id, 'state' => 1], 'owner_id'
    );
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => $nombre, 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
    ]);

    return [$tendero, $negocio];
}

it('el dueno de la tienda a la que reparte SI la lee', function () {
    [$d] = unDomiciliarioYSuUsuario();

    [$dueno, $negocio] = unTenderoConTiendaParaLaFicha('Tienda Ana');

    DB::table('business_domiciliary')->insert([
        'busines_id' => $negocio, 'domiciliary_id' => $d->domiciliary_id, 'state' => 1,
    ]);

    Sanctum::actingAs($dueno);

    $r = $this->postJson('/v1/domiciliaries/showDomiciliary', [
        'domiciliary_id' => $d->domiciliary_id,
    ]);

    /* La tienda es quien le guarda los papeles, así que SÍ ve la cédula. Es la
       misma regla que ya regía para editarlo. */
    expect($r->status())->toBe(200);
    expect($r->json('document'))->toBe('1020304050');
});

it('un dueno de OTRA tienda no la lee', function () {
    [$d] = unDomiciliarioYSuUsuario();

    /* Tiene tienda, pero el domiciliario NO reparte para ella: no se vincula
       nada en `business_domiciliary`. Es la mitad que separa «es tendero» de
       «es SU domiciliario», y sin ella la prueba de arriba pasaría con una
       regla que solo mirara el rol. */
    [$ajeno] = unTenderoConTiendaParaLaFicha('Tienda Beto');

    Sanctum::actingAs($ajeno);

    expect($this->postJson('/v1/domiciliaries/showDomiciliary', [
        'domiciliary_id' => $d->domiciliary_id,
    ])->status())->toBe(403);
});

it('CONTROL: la sesion del comprador si llega a lo que es suyo', function () {
    /*
     * La mitad que hace que los 403 de arriba signifiquen algo. Un 403 no
     * prueba que la autorización funcione si resulta que en esta suite TODO
     * da 403 —una sesión mal montada, un middleware que no corre—. Así que se
     * comprueba que el mismo comprador, con la misma sesión, SÍ pasa por una
     * puerta que le corresponde.
     */
    unRolDeCada();
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    expect($this->getJson('/v1/profile')->status())->toBe(200);
});

it('la forma de la respuesta NO cambio: las mismas claves que el modelo pelado', function () {
    /*
     * ESTA ES LA PRUEBA DE QUE NO SE ROMPIÓ NADA, y es la que hace que el
     * Resource valga: se compara con lo que devolvía `response()->json($d)`,
     * que es el `toArray()` del modelo. Si las claves no fueran las mismas, la
     * app instalada se quedaría sin un campo y no fallaría nada: simplemente
     * faltaría.
     */
    [$d] = unDomiciliarioYSuUsuario();

    $cargado = Domiciliary::with('user', 'reviews')->find($d->domiciliary_id);

    $pelado = $cargado->toArray();
    $porResource = (new MiFichaDeDomiciliarioResource($cargado))->resolve();

    expect(array_keys($porResource))->toBe(array_keys($pelado));
    expect(array_keys($porResource['user']))->toBe(array_keys($pelado['user']));
    expect($porResource['document'])->toBe($pelado['document']);
    expect($porResource['user']['email'])->toBe($pelado['user']['email']);
});

it('una columna nueva ya NO se publica sola', function () {
    /*
     * Lo que SÍ cambia, y es el motivo del Resource. Antes la forma era «las
     * columnas que tenga la tabla»: una migración que añadiera un campo
     * —una nota del equipo, un identificador de otro sistema— lo convertía en
     * campo público de la API sin que nadie lo decidiera. En esta tabla eso
     * pesa más que en otras, porque lo que ya hay dentro es una cédula.
     *
     * Se carga la fila como la carga Eloquent al leer, con una columna que la
     * lista no conoce, y se comprueba que el Resource NO la saca y que el
     * crudo SÍ —el grupo de control, sin el cual «no sale» es verdad también
     * cuando no sale nada—.
     */
    $d = new Domiciliary;
    $d->setRawAttributes([
        'domiciliary_id' => 5,
        'user_id' => 11,
        'available' => 1,
        'document' => '1020304050',
        'qualification' => '4.60',
        'state' => 1,
        'columna_recien_migrada' => 'no deberia salir',
    ], true);

    $publicado = (new MiFichaDeDomiciliarioResource($d))->resolve();

    expect($publicado)->not->toHaveKey('columna_recien_migrada');
    expect($d->toArray())->toHaveKey('columna_recien_migrada');
});

<?php

declare(strict_types=1);

use App\Models\Domiciliary;
use App\Models\Rol;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * `POST /v1/domiciliaries/showDomiciliary` NO COMPRUEBA DE QUIÉN ES LA FICHA.
 *
 * MEDIDO, NO ARREGLADO. Esta prueba describe lo que el servidor hace HOY, y
 * está escrita para que se ponga roja el día que se arregle: eso es la señal
 * de que se arregló, y entonces hay que borrarla.
 *
 * QUÉ HACE EL MÉTODO:
 *
 *     $domiciliary = Domiciliary::with('user', 'reviews')->find($request->domiciliary_id);
 *     return response()->json($domiciliary);
 *
 * El identificador llega EN EL CUERPO de la petición y nada lo compara con
 * quien pregunta. La ruta está en el grupo `domiciliaries` (routes/api.php:799)
 * y ese grupo no añade guardia: lo único que hay encima es el
 * `['auth:sanctum', 'audit.api']` de la línea 264. Así que basta tener sesión
 * —cualquiera, un comprador— y mandar un número.
 *
 * QUÉ SE PUBLICA, y por eso importa. Como no hay lista de claves, la respuesta
 * es «las columnas que tenga la tabla», y entre ellas está `document`: LA
 * CÉDULA. Es el dato más sensible que un domiciliario tiene en la plataforma,
 * y el repositorio ya tiene decidido dónde debe aparecer —solo en la portería
 * del conjunto, para que el celador compare la cédula con quien tiene
 * delante—. Está medido y escrito en `LasSeisCarasDelDomiciliarioTest`:
 *
 *     el documento sale SOLO en la porteria   (allí se comprueba)
 *     y aquí sale a cualquiera con sesión     (aquí se comprueba)
 *
 * Las dos pruebas son verdad a la vez. La regla se cumple en las seis formas
 * que pasan por un Resource y se salta en la que devuelve el modelo pelado.
 * Es el mismo patrón que `credit_debt_cap`: la decisión está tomada en un
 * sitio e incumplida en otro.
 *
 * POR QUÉ NO SE ARREGLA ACÁ. Son dos cambios, y los dos son decisión de
 * producto:
 *
 *   · ACOTAR POR SESIÓN. El único que llama a esto es el panel del propio
 *     domiciliario --`useDomiciliary.js:192`, con
 *     `user?.domiciliary?.domiciliary_id`, o sea SU id-- así que acotarlo no
 *     rompería al único cliente conocido. Pero es una ruta de dos apps
 *     publicadas y un 403 donde antes había 200 deja al domiciliario sin
 *     perfil si el id que manda no es el que el servidor calcula.
 *   · ENUMERAR LAS CLAVES. Cambia la forma de una respuesta que una app ya
 *     instalada lee, que es justo lo que este trabajo no hace.
 *
 * SE DESCUBRIÓ MIDIENDO, no leyendo. `scripts/formas_de_entidad.py` no veía
 * los `response()->json($x)` sin lista de claves; al cerrarle ese punto ciego
 * salieron cuatro, y este era uno.
 */
function unDomiciliarioConCedula(string $cedula = '1020304050'): Domiciliary
{
    foreach ([1, 3] as $id) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => 'rol'.$id, 'guard_name' => 'web']);
    }

    $u = User::factory()->create(['rol' => 3]);

    return Domiciliary::create([
        'user_id' => $u->user_id,
        'available' => 1,
        'document' => $cedula,
        'qualification' => 4.6,
        'state' => 1,
    ]);
}

it('un comprador con sesion lee la cedula de un domiciliario cualquiera', function () {
    $domiciliario = unDomiciliarioConCedula('1020304050');

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'rol1', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $r = $this->postJson('/v1/domiciliaries/showDomiciliary', [
        'domiciliary_id' => $domiciliario->domiciliary_id,
    ]);

    /* Doscientos. No 403, no 404: la ficha entera. */
    expect($r->status())->toBe(200);

    /* Y la cédula dentro. Se comprueba contra el CONTENIDO y no contra una
       clave, porque si algún día se enumeran las claves y se deja `document`
       fuera, lo que tiene que delatarlo es que el número no esté. */
    expect($r->getContent())->toContain('1020304050');

    /* Y de paso, qué más viaja: el correo y el teléfono del domiciliario, por
       la relación `user` que el método carga con `with()`. */
    $cuerpo = $r->json();

    expect($cuerpo)->toHaveKey('document');
    expect($cuerpo['user'])->toHaveKey('email');
    expect($cuerpo['user'])->toHaveKey('phone');
});

it('CONTROL: la misma sesion NO llega a una ruta que si esta acotada', function () {
    /*
     * La mitad que hace que lo de arriba signifique algo. Un 200 no prueba
     * que falte la autorización si resulta que en esta suite TODO devuelve
     * 200 --una sesión de más, un middleware que no corre en pruebas--. Así
     * que se comprueba que el mismo comprador, con la misma sesión, SÍ se
     * estrella contra una puerta que está cerrada de verdad.
     *
     * `/v1/users` es la que se retiró por esto mismo, y da 404.
     */
    unDomiciliarioConCedula();

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'rol1', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    expect($this->getJson('/v1/users')->status())->toBe(404);
});

it('la ficha no lleva lista de claves: lo que se publica es la tabla', function () {
    /*
     * Lo que esto fija es distinto de la fuga: que la respuesta NO tiene
     * forma escrita. Si mañana una migración añade una columna a
     * `domiciliary` --una nota del equipo, un identificador de otro
     * sistema--, sale publicada sola. Esta prueba lo dice con una columna
     * que no existe en el esquema: se mete en la fila cargada, como hace
     * Eloquent al leer, y se comprueba que el `toArray()` la saca.
     *
     * Es el mismo control que `LaOctavaTandaFiltraColumnasNuevasTest` usa al
     * revés: allí prueba que un Resource la filtra; acá, que sin Resource no
     * la filtra nadie.
     */
    $d = new Domiciliary;
    $d->setRawAttributes([
        'domiciliary_id' => 5,
        'user_id' => 11,
        'available' => 1,
        'document' => '1020304050',
        'qualification' => '4.60',
        'state' => 1,
        'columna_recien_migrada' => 'esto saldria publicado',
    ], true);

    expect($d->toArray())->toHaveKey('columna_recien_migrada');
    expect($d->toArray()['columna_recien_migrada'])->toBe('esto saldria publicado');
});

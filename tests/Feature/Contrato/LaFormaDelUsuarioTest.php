<?php

declare(strict_types=1);

use App\Http\Resources\Usuario\UsuarioResource;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LA FORMA DEL USUARIO: ni una columna de más, ni una relación de menos.
 *
 * Seis sitios devolvían `$user` crudo —el login, el acceso biométrico, el
 * perfil, dos del alta de domiciliarios y la corrección del propio perfil—,
 * así que la respuesta era «las columnas que tenga la tabla `user`». Es la
 * entidad más sensible del sistema y una migración que añadiera un campo lo
 * publicaba sin que nadie lo decidiera.
 *
 * ESTE ARCHIVO VIGILA LAS DOS MITADES, y la segunda es la que casi se rompe:
 *
 *  1. Una columna nueva NO sale. Se añade una de verdad a `user` en la base
 *     de pruebas y se comprueba que no aparece.
 *
 *  2. Las relaciones cargadas SÍ salen. El login hace `load('buyer')`,
 *     `load('owner.businesses')` o `load('domiciliary.businesses')` según el
 *     rol, porque la app las necesita para saber a qué pantalla llevar a la
 *     persona. Una lista blanca que solo dejara columnas las habría TIRADO y
 *     habría roto el arranque en tres de los cuatro roles. Esa es la prueba
 *     que justifica que los tres nombres estén en la lista.
 *
 * Lo que este archivo NO vigila, dicho para que no se lea como más de lo que
 * es: dentro de `buyer`, `owner` y `domiciliary` los modelos se siguen
 * serializando enteros, así que una columna nueva ahí sí se publica sola.
 * Cerrar eso es otra tanda.
 */
function unUsuarioDeRol(int $rol): User
{
    Rol::firstOrCreate(['rol_id' => $rol], ['name' => 'rol'.$rol, 'guard_name' => 'web']);

    return User::factory()->create(['rol' => $rol]);
}

it('una columna nueva en user NO sale en la respuesta', function () {
    $u = unUsuarioDeRol(1);

    Schema::table('user', function ($t) {
        // El nombre lo dice: es la clase de columna que no debe salir.
        $t->string('nota_interna_de_riesgo')->nullable();
    });

    DB::table('user')->where('user_id', $u->user_id)
        ->update(['nota_interna_de_riesgo' => 'cuenta marcada por soporte']);

    $forma = (new UsuarioResource(User::find($u->user_id)))
        ->toArray(Request::create('/v1/profile', 'GET'));

    expect($forma)->not->toHaveKey('nota_interna_de_riesgo');

    // Y la contrapartida: la columna existe y el modelo la ve. Sin esto, la
    // prueba pasaría si la migración hubiera fallado en silencio.
    expect(Schema::hasColumn('user', 'nota_interna_de_riesgo'))->toBeTrue();
    expect(User::find($u->user_id)->nota_interna_de_riesgo)
        ->toBe('cuenta marcada por soporte');

    Schema::table('user', fn ($t) => $t->dropColumn('nota_interna_de_riesgo'));
});

it('las relaciones que carga el login siguen llegando, en los tres roles', function () {
    /*
     * LA PRUEBA QUE SALVA EL ARRANQUE DE LA APP. Si alguien «limpia» la lista
     * de campos dejando solo columnas, estas tres se ponen rojas antes de que
     * nadie publique nada.
     */
    $comprador = unUsuarioDeRol(1);
    DB::table('buyer')->insert([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    $tendero = unUsuarioDeRol(2);
    DB::table('owner')->insert(['user_id' => $tendero->user_id, 'state' => 1]);

    $repartidor = unUsuarioDeRol(3);
    DB::table('domiciliary')->insert([
        'user_id' => $repartidor->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ]);

    $req = Request::create('/v1/login', 'POST');

    foreach ([
        [$comprador, 'buyer', 'buyer'],
        [$tendero, 'owner.businesses', 'owner'],
        [$repartidor, 'domiciliary.businesses', 'domiciliary'],
    ] as [$u, $carga, $clave]) {
        $fresco = User::find($u->user_id);
        $fresco->load($carga);

        $forma = (new UsuarioResource($fresco))->toArray($req);

        /*
         * `array_key_exists` y no `toHaveKey($clave, $mensaje)`: el segundo
         * argumento de Pest es el VALOR esperado de la clave, no un mensaje.
         * Usarlo como mensaje compara el mensaje contra la relacion y falla
         * diciendo «does not match expected type string», que manda a buscar
         * al sitio equivocado. Ya pico una vez en esta misma tanda de
         * trabajo.
         */
        expect(array_key_exists($clave, $forma))->toBeTrue(
            "El rol {$u->rol} pierde «{$clave}», que es lo que la app usa para elegir pantalla",
        );

        /*
         * Y que la relación llega IGUAL que sin el Resource. Es la afirmación
         * fuerte: no basta con que la clave esté, tiene que traer lo mismo.
         */
        expect($forma[$clave])->toEqual($fresco->toArray()[$clave]);
    }
});

it('una columna nueva en buyer, owner, domiciliary o business NO sale tampoco', function () {
    /*
     * LA SEGUNDA MITAD DEL AGUJERO, cerrada el 12 de noviembre de 2026.
     *
     * `UsuarioResource` nacio pasando las tres relaciones del login TAL CUAL,
     * y eso dejaba el problema a un nivel de profundidad: la lista de campos
     * del usuario estaba escrita, pero `buyer`, `owner`, `domiciliary` y los
     * `businesses` que cuelgan de los dos ultimos se seguian serializando
     * enteros. Una columna nueva ahi se publicaba sola en el login.
     *
     * Esta prueba añade una columna de verdad a las CUATRO tablas y comprueba
     * que ninguna aparece. Es la misma forma que la de arriba, aplicada un
     * nivel mas abajo, que es donde el primer arreglo no llegaba.
     */
    $tendero = unUsuarioDeRol(2);
    $ownerId = DB::table('owner')->insertGetId([
        'user_id' => $tendero->user_id, 'state' => 1,
    ], 'owner_id');

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La del arreglo', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
    ]);

    $comprador = unUsuarioDeRol(1);
    DB::table('buyer')->insert([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    $repartidor = unUsuarioDeRol(3);
    DB::table('domiciliary')->insert([
        'user_id' => $repartidor->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ]);

    foreach (['buyer', 'owner', 'domiciliary', 'business'] as $tabla) {
        Schema::table($tabla, fn ($t) => $t->string('campo_colado')->nullable());
        DB::table($tabla)->update(['campo_colado' => 'no deberia salir']);
    }

    $req = Request::create('/v1/login', 'POST');

    $casos = [
        [$comprador, 'buyer', 'buyer'],
        [$tendero, 'owner.businesses', 'owner'],
        [$repartidor, 'domiciliary.businesses', 'domiciliary'],
    ];

    foreach ($casos as [$u, $carga, $clave]) {
        $fresco = User::find($u->user_id);
        $fresco->load($carga);

        $forma = (new UsuarioResource($fresco))->toArray($req);

        expect($forma[$clave] ?? [])->not->toHaveKey(
            'campo_colado',
            // Sin mensaje: el segundo argumento de toHaveKey es el valor.
        );

        // Y en los negocios que cuelgan, que es un nivel mas abajo todavia.
        foreach ($forma[$clave]['businesses'] ?? [] as $b) {
            expect($b)->not->toHaveKey('campo_colado');
        }
    }

    // La contrapartida: las columnas existen y los modelos las ven. Sin esto,
    // la prueba pasaria si los `Schema::table` hubieran fallado en silencio.
    foreach (['buyer', 'owner', 'domiciliary', 'business'] as $tabla) {
        expect(Schema::hasColumn($tabla, 'campo_colado'))->toBeTrue();
        Schema::table($tabla, fn ($t) => $t->dropColumn('campo_colado'));
    }
});

it('el tope de credito de la tienda sigue viajando al tendero Y al domiciliario', function () {
    /*
     * ESTO NO ES UNA PRUEBA DE QUE ALGO FUNCIONE. Fija un hallazgo para que
     * arreglarlo sea una decision.
     *
     * `credit_debt_cap` esta documentado en `NegocioRequest` con estas
     * palabras: «lo que la tienda puede fiar entre todos sus clientes y la
     * deuda que puede arrastrar con la plataforma. Solo lo pone el equipo; EL
     * PANEL DEL TENDERO NO LO RECIBE».
     *
     * Lo recibe. Y el domiciliario tambien, de cada negocio con el que
     * trabaja, junto con `max_courier_cash`. Medido contra la base de
     * desarrollo: 1000000.00.
     *
     * El dia que se quite, esta prueba se pone roja y obliga a comprobar que
     * ninguna pantalla lo estaba leyendo.
     */
    $tendero = unUsuarioDeRol(2);
    $ownerId = DB::table('owner')->insertGetId([
        'user_id' => $tendero->user_id, 'state' => 1,
    ], 'owner_id');

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La que fia', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        'credit_debt_cap' => 750000,
    ], 'busines_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
    ]);

    $repartidor = unUsuarioDeRol(3);
    $domiId = DB::table('domiciliary')->insertGetId([
        'user_id' => $repartidor->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');
    DB::table('business_domiciliary')->insert([
        'busines_id' => $negocio, 'domiciliary_id' => $domiId, 'state' => 1,
    ]);

    $req = Request::create('/v1/login', 'POST');

    foreach ([[$tendero, 'owner.businesses', 'owner'],
        [$repartidor, 'domiciliary.businesses', 'domiciliary']] as [$u, $carga, $clave]) {
        $fresco = User::find($u->user_id);
        $fresco->load($carga);

        $forma = (new UsuarioResource($fresco))->toArray($req);
        $negocios = $forma[$clave]['businesses'] ?? [];

        expect($negocios)->not->toBeEmpty();
        expect((float) $negocios[0]['credit_debt_cap'])->toBe(750000.0);
    }
});

it('el usuario del panel sale sin esas tres claves, igual que antes', function () {
    /*
     * La contrapartida de la prueba de arriba: el rol 4 no carga ninguna de
     * las tres, y una lista blanca no debe inventarlas como nulas. Si
     * aparecieran vacías, el panel recibiría tres claves nuevas.
     */
    $admin = unUsuarioDeRol(4);

    $forma = (new UsuarioResource(User::find($admin->user_id)))
        ->toArray(Request::create('/v1/profile', 'GET'));

    expect($forma)->not->toHaveKey('buyer');
    expect($forma)->not->toHaveKey('owner');
    expect($forma)->not->toHaveKey('domiciliary');
});

it('sigue sin salir la contrasena ni el secreto de dos factores', function () {
    /*
     * Lo que ya protegía `$hidden` del modelo, afirmado aquí porque ahora hay
     * una segunda lista —la del Resource— y dos listas pueden discrepar. Esto
     * fija que la de abajo no reabra lo que la de arriba cerró.
     */
    $u = unUsuarioDeRol(1);

    $forma = (new UsuarioResource(User::find($u->user_id)))
        ->toArray(Request::create('/v1/profile', 'GET'));

    foreach (['password', 'remember_token', 'two_factor_secret',
        'two_factor_recovery_codes', 'area'] as $prohibido) {
        expect($forma)->not->toHaveKey($prohibido);
    }
});

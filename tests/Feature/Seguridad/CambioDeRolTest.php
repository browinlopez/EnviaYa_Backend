<?php

/**
 * CAMBIAR EL ROL DE ALGUIEN NO ES CAMBIAR UN NÚMERO.
 *
 * `user.rol` decide por cuál de las tres puertas entra una persona, pero lo que
 * le deja hacer del otro lado vive en otras tablas: `owner_busines` para el
 * tendero, `complex_staff` para el conjunto, `user.area_id` para el personal
 * interno.
 *
 * Editar solo el número dejaba cuentas a medias en las DOS direcciones, y
 * ninguna avisaba:
 *
 *  · HACIA ARRIBA — subir a alguien a rol 5 no le creaba su fila en
 *    `complex_staff`. Iniciaba sesión en el panel de aliados y no veía nada.
 *    Era el mismo fallo que ya se había corregido al CREAR usuarios, entrando
 *    por la puerta de editar.
 *
 *  · HACIA ABAJO — bajarle el rol a un tendero o a un dueño de conjunto no le
 *    quitaba el acceso: `GET /v1/negocio/me` y `GET /v1/conjunto/residentes`
 *    seguían respondiendo 200, porque las dos puertas resolvían por la fila de
 *    vínculo sin mirar el rol.
 *
 * Lo de abajo se cerró en los dos sitios a propósito: en el origen —el cambio
 * de rol ahora exige desenganchar primero, o desengancha él— y en la puerta,
 * que es donde tiene que estar la defensa. Una fila que se quede atrás por
 * cualquier otro camino no puede volver a valer como llave.
 */

use App\Models\Area;
use App\Models\Buyer\ResidentialComplex;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Los seis roles existen en la tabla, que no es autoincremental. */
function losSeisRoles(): void
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario',
              4 => 'admin', 5 => 'dueno_conjunto', 6 => 'celador'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }
}

function comoDelPanelDeUsuarios(): User
{
    losSeisRoles();

    $u = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => Area::NIVEL_GESTOR,
    ]);

    Sanctum::actingAs($u);

    return $u;
}

function unConjuntoCualquiera(string $nombre = 'Los Almendros'): ResidentialComplex
{
    return ResidentialComplex::create([
        'name' => $nombre, 'address' => 'Carrera 51B #87-50', 'state' => 1,
    ]);
}

/** Un tendero de verdad: con su `owner` y su negocio atado. */
function unTenderoConTienda(string $tienda = 'Tienda La Esquina'): User
{
    $user = User::factory()->create(['rol' => 2]);

    $ownerId = DB::table('owner')->insertGetId(['user_id' => $user->user_id, 'state' => 1]);
    $negocio = DB::table('business')->insertGetId([
        'name' => $tienda, 'state' => 1, 'qualification' => 0,
    ]);
    DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $negocio]);

    return $user;
}

/* ===================================================================== */
/*  HACIA ARRIBA: LA CUENTA QUE NACÍA A MEDIAS                           */
/* ===================================================================== */

test('subir a alguien a rol de conjunto sin decir cual exige el conjunto', function () {
    comoDelPanelDeUsuarios();
    $persona = User::factory()->create(['rol' => 1]);

    $this->putJson("/v1/admin/users/{$persona->user_id}", ['rol' => ComplexStaff::ROL_DUENO])
        ->assertStatus(422);

    // Y no se movió nada: ni el rol a medias, ni una ficha suelta.
    expect((int) $persona->fresh()->rol)->toBe(1);
    expect(ComplexStaff::where('user_id', $persona->user_id)->count())->toBe(0);
});

test('con el conjunto puesto, el cambio crea la ficha que lo hace servir', function () {
    comoDelPanelDeUsuarios();
    $conjunto = unConjuntoCualquiera();
    $persona  = User::factory()->create(['rol' => 1]);

    $this->putJson("/v1/admin/users/{$persona->user_id}", [
        'rol'        => ComplexStaff::ROL_DUENO,
        'complex_id' => $conjunto->complex_id,
    ])->assertOk();

    $ficha = ComplexStaff::where('user_id', $persona->user_id)->first();

    expect($ficha)->not->toBeNull();
    expect((int) $ficha->complex_id)->toBe((int) $conjunto->complex_id);
    expect($ficha->role)->toBe(ComplexStaff::DUENO);
    expect($ficha->state)->toBeTrue();
});

test('y entonces la cuenta entra de verdad al panel de aliados', function () {
    comoDelPanelDeUsuarios();
    $conjunto = unConjuntoCualquiera();
    $persona  = User::factory()->create(['rol' => 1]);

    $this->putJson("/v1/admin/users/{$persona->user_id}", [
        'rol'        => ComplexStaff::ROL_DUENO,
        'complex_id' => $conjunto->complex_id,
    ])->assertOk();

    // Lo que fallaba antes no era el 422: era que la cuenta quedaba muda.
    Sanctum::actingAs($persona->fresh());
    $this->getJson('/v1/conjunto/residentes')->assertOk();
});

test('un comprador nuevo por cambio de rol recibe su ficha de comprador', function () {
    comoDelPanelDeUsuarios();
    $persona = User::factory()->create(['rol' => 3]);
    DB::table('domiciliary')->insert([
        'user_id' => $persona->user_id, 'available' => 0, 'qualification' => 0, 'state' => 1,
    ]);

    $this->putJson("/v1/admin/users/{$persona->user_id}", ['rol' => 1])->assertOk();

    // Sin fila en `buyer` la app falla al cargar su perfil.
    expect(DB::table('buyer')->where('user_id', $persona->user_id)->exists())->toBeTrue();
});

test('subir a alguien a personal interno exige un area', function () {
    comoDelPanelDeUsuarios();
    $persona = User::factory()->create(['rol' => 1]);

    // Un rol 4 sin área es una cuenta que el propio login rechaza.
    $this->putJson("/v1/admin/users/{$persona->user_id}", ['rol' => 4])
        ->assertStatus(422);

    expect((int) $persona->fresh()->rol)->toBe(1);
});

test('crear un rol 4 desde el panel tambien exige el area', function () {
    comoDelPanelDeUsuarios();

    $this->postJson('/v1/admin/users', [
        'name' => 'Sin Area', 'email' => 'sin.area@ejemplo.test',
        'password' => 'Local2026*', 'rol' => 4,
    ])->assertStatus(422);
});

/* ===================================================================== */
/*  HACIA ABAJO: EL ACCESO QUE NO SE IBA                                 */
/* ===================================================================== */

test('a un tendero con tienda no se le cambia el rol de paso', function () {
    comoDelPanelDeUsuarios();
    $tendero = unTenderoConTienda();

    $r = $this->putJson("/v1/admin/users/{$tendero->user_id}", ['rol' => 1])
        ->assertStatus(422);

    // El mensaje dice DÓNDE se resuelve: sin eso el rechazo es un muro.
    expect($r->json('message'))->toContain('Propietarios');
    expect((int) $tendero->fresh()->rol)->toBe(2);
});

test('al administrador de un conjunto tampoco, para no dejarlo sin cabeza', function () {
    comoDelPanelDeUsuarios();
    $conjunto = unConjuntoCualquiera('Villa Carolina');
    $dueno = User::factory()->create(['rol' => ComplexStaff::ROL_DUENO]);
    ComplexStaff::create([
        'user_id' => $dueno->user_id, 'complex_id' => $conjunto->complex_id,
        'role' => ComplexStaff::DUENO, 'state' => true,
    ]);

    $r = $this->putJson("/v1/admin/users/{$dueno->user_id}", ['rol' => 1])
        ->assertStatus(422);

    expect($r->json('message'))->toContain('Villa Carolina');
});

test('a un celador si, y su ficha se DESACTIVA en vez de borrarse', function () {
    comoDelPanelDeUsuarios();
    $conjunto = unConjuntoCualquiera();
    $celador = User::factory()->create(['rol' => ComplexStaff::ROL_CELADOR]);
    ComplexStaff::create([
        'user_id' => $celador->user_id, 'complex_id' => $conjunto->complex_id,
        'role' => ComplexStaff::CELADOR, 'state' => true,
    ]);

    $this->putJson("/v1/admin/users/{$celador->user_id}", ['rol' => 1])->assertOk();

    /*
     * Se desactiva y no se borra: tiene entradas de portería registradas a su
     * nombre, y borrar la ficha dejaría constancias sin autor. Es el mismo
     * criterio con el que el dueño desactiva a sus celadores.
     */
    $ficha = ComplexStaff::where('user_id', $celador->user_id)->first();
    expect($ficha)->not->toBeNull();
    expect($ficha->state)->toBeFalse();

    Sanctum::actingAs($celador->fresh());
    $this->getJson('/v1/conjunto/resumen')->assertForbidden();
});

/* ===================================================================== */
/*  LA DEFENSA EN LA PUERTA                                              */
/* ===================================================================== */

test('una ficha de conjunto que se quedo atras NO vale como llave', function () {
    losSeisRoles();
    $conjunto = unConjuntoCualquiera();

    // Se fuerza el estado que el panel ya no permite crear: rol de comprador
    // con la ficha del conjunto todavía activa. Puede llegar por una carga de
    // datos o por un arreglo a mano en la base.
    $persona = User::factory()->create(['rol' => 1]);
    ComplexStaff::create([
        'user_id' => $persona->user_id, 'complex_id' => $conjunto->complex_id,
        'role' => ComplexStaff::DUENO, 'state' => true,
    ]);

    Sanctum::actingAs($persona);

    $this->getJson('/v1/conjunto/residentes')->assertForbidden();
    $this->getJson('/v1/conjunto/resumen')->assertForbidden();
});

test('una cadena de propiedad que se quedo atras tampoco', function () {
    losSeisRoles();
    $tendero = unTenderoConTienda();

    // Con su rol entra.
    Sanctum::actingAs($tendero);
    $this->getJson('/v1/negocio/me')->assertOk();

    // Bajado a comprador por fuera del panel, la cadena sigue intacta.
    DB::table('user')->where('user_id', $tendero->user_id)->update(['rol' => 1]);

    Sanctum::actingAs($tendero->fresh());
    $this->getJson('/v1/negocio/me')->assertForbidden();
});

/* ===================================================================== */
/*  LAS SESIONES QUE SEGUÍAN ABIERTAS                                    */
/* ===================================================================== */

/*
 * Estas dos NO usan `Sanctum::actingAs`, y no es un capricho: `actingAs` fija
 * el usuario del guardia para toda la prueba, así que la petición con la
 * cabecera `Authorization` seguiría resolviendo al administrador y el token de
 * la víctima nunca se comprobaría. La prueba pasaría sin probar nada. Acá cada
 * quien lleva su token de verdad.
 */

/** Un administrador con token real, para llamar al panel sin `actingAs`. */
function tokenDelPanel(): string
{
    losSeisRoles();

    $u = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => Area::NIVEL_GESTOR,
    ]);

    return $u->createToken('panel')->plainTextToken;
}

test('desactivar una cuenta le cierra las sesiones que ya tenia', function () {
    $delPanel = tokenDelPanel();

    $persona = User::factory()->create(['rol' => 1, 'state' => 1]);
    $suyo    = $persona->createToken('app')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$suyo}")
        ->getJson('/v1/profile')->assertOk();

    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$delPanel}")
        ->putJson("/v1/admin/users/{$persona->user_id}", ['state' => 0])
        ->assertOk();

    /*
     * `forgetGuards()` antes de volver a preguntar: dentro de una misma prueba
     * el guardia guarda el usuario que ya resolvió, así que sin esto la segunda
     * petición contesta con el de la primera y la prueba pasaría sin comprobar
     * nada. Es un detalle del banco de pruebas, no del producto.
     */
    $this->app['auth']->forgetGuards();

    /*
     * Apagar la cuenta bloqueaba el login, no la sesión abierta: a quien se
     * acababa de desactivar le seguía sirviendo su token hasta que caducara.
     */
    $this->withHeader('Authorization', "Bearer {$suyo}")
        ->getJson('/v1/profile')->assertUnauthorized();
});

test('cambiar de rol tambien: el token se emitio para otra puerta', function () {
    $delPanel = tokenDelPanel();
    $conjunto = unConjuntoCualquiera();

    $persona = User::factory()->create(['rol' => 1]);
    $suyo    = $persona->createToken('app')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$delPanel}")
        ->putJson("/v1/admin/users/{$persona->user_id}", [
            'rol' => ComplexStaff::ROL_CELADOR, 'complex_id' => $conjunto->complex_id,
        ])->assertOk();

    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$suyo}")
        ->getJson('/v1/profile')->assertUnauthorized();
});

/* ===================================================================== */
/*  LO QUE NO SE PUEDE ROMPER AL ARREGLAR ESTO                           */
/* ===================================================================== */

test('editar el telefono de un dueno no le pide el conjunto otra vez', function () {
    comoDelPanelDeUsuarios();
    $conjunto = unConjuntoCualquiera();
    $dueno = User::factory()->create(['rol' => ComplexStaff::ROL_DUENO]);
    ComplexStaff::create([
        'user_id' => $dueno->user_id, 'complex_id' => $conjunto->complex_id,
        'role' => ComplexStaff::DUENO, 'state' => true,
    ]);

    /*
     * El panel manda el rol SIEMPRE, también cuando no cambió. Si la regla del
     * conjunto se disparara con la sola presencia del campo, corregir un
     * teléfono habría empezado a pedir el conjunto sin motivo.
     */
    $this->putJson("/v1/admin/users/{$dueno->user_id}", [
        'rol' => ComplexStaff::ROL_DUENO, 'phone' => '3001234567',
    ])->assertOk();

    expect($dueno->fresh()->phone)->toBe('3001234567');
    expect(ComplexStaff::where('user_id', $dueno->user_id)->where('state', true)->count())->toBe(1);
});

test('el area no se toca desde esta pantalla si el rol no cambia', function () {
    $yo = comoDelPanelDeUsuarios();
    $otro = User::factory()->create([
        'rol' => 4,
        'area_id' => Area::where('code', 'marketing')->firstOrFail()->id,
        'access_level' => Area::NIVEL_GESTOR,
    ]);

    /*
     * Para mover áreas está Control → Áreas, que tiene las salvaguardas que
     * esta pantalla no: no dejarse a uno mismo sin acceso, no bajarse el propio
     * nivel. Aceptarlo también acá habría sido una segunda puerta sin ellas.
     */
    $this->putJson("/v1/admin/users/{$otro->user_id}", ['area_id' => null, 'rol' => 4])
        ->assertOk();

    expect($otro->fresh()->area_id)->not->toBeNull();
});

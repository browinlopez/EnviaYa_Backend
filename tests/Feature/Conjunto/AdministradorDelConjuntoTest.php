<?php

/**
 * DARLE UN RESPONSABLE A UN CONJUNTO DESDE EL PANEL.
 *
 * Un conjunto recién creado no le sirve a nadie hasta que alguien pueda entrar
 * al panel de aliados a administrarlo, y eso son DOS cosas que van juntas:
 *
 *   · la cuenta con `rol = 5`, que es lo que mira `EnsureComplexStaff`;
 *   · la ficha en `complex_staff`, que es la que dice CUÁL conjunto es el suyo.
 *
 * Con la primera sola la cuenta inicia sesión y no ve absolutamente nada,
 * porque todo el panel de aliados se acota por el conjunto de la ficha. Es un
 * fallo que solo se nota del otro lado y sin ningún mensaje, así que casi todo
 * lo de acá comprueba que las dos cosas ocurren o no ocurre ninguna.
 *
 * Hasta ahora esto solo existía como comando de consola
 * (`php artisan conjunto:dueno`), y quien da de alta el conjunto desde el panel
 * no tiene acceso al servidor.
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

/** Alguien del área de sistema: tiene todos los módulos por definición. */
function comoAdministrador(string $nivel = Area::NIVEL_GESTOR): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $u = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => $nivel,
    ]);

    Sanctum::actingAs($u);

    return $u;
}

function unConjunto(string $nombre = 'Los Almendros'): ResidentialComplex
{
    return ResidentialComplex::create([
        'name'                 => $nombre,
        'address'              => 'Carrera 51B #87-50',
        'people_count'         => 200,
        'towers_count'         => 4,
        'apartments_per_tower' => 50,
        'state'                => 1,
    ]);
}

/* ===================================================================== */
/*  EL ESTADO EN EL QUE NACE                                             */
/* ===================================================================== */

test('un conjunto recien creado sale marcado como sin administrador', function () {
    comoAdministrador();
    $conjunto = unConjunto();

    $fila = collect($this->getJson('/v1/admin/complexes')->assertOk()->json())
        ->firstWhere('complex_id', $conjunto->complex_id);

    /*
     * Que el panel pueda DECIRLO es la mitad del arreglo. Sin este dato, un
     * conjunto sin responsable se ve idéntico a uno que sí lo tiene y nadie se
     * entera hasta que el administrador llama diciendo que no puede entrar.
     */
    expect($fila['admin_user_id'])->toBeNull()
        ->and($fila['admin_email'])->toBeNull()
        ->and($fila['guards_count'])->toBe(0);
});

/* ===================================================================== */
/*  ASIGNARLO                                                            */
/* ===================================================================== */

test('asignar administrador crea la cuenta y la ficha del conjunto', function () {
    comoAdministrador();
    $conjunto = unConjunto();

    $r = $this->postJson("/v1/admin/complexes/{$conjunto->complex_id}/owner", [
        'name'  => 'Administración Los Almendros',
        'email' => 'almendros@ejemplo.com',
        'phone' => '3001234567',
    ])->assertCreated();

    $usuario = User::where('email', 'almendros@ejemplo.com')->first();

    expect($usuario)->not->toBeNull()
        ->and((int) $usuario->rol)->toBe(ComplexStaff::ROL_DUENO)
        // Lo da de alta un administrador: sin verificar quedaría bloqueado al
        // iniciar sesión, que es como entregarle un acceso que no funciona.
        ->and($usuario->email_verified_at)->not->toBeNull();

    $ficha = ComplexStaff::where('user_id', $usuario->user_id)->first();

    expect($ficha)->not->toBeNull()
        ->and($ficha->complex_id)->toBe($conjunto->complex_id)
        ->and($ficha->role)->toBe(ComplexStaff::DUENO)
        ->and($ficha->state)->toBeTrue();

    // La clave generada se devuelve UNA vez: es como se entrega un acceso sin
    // dejarlo escrito en ningún sitio.
    expect($r->json('password'))->toBeString()->not->toBeEmpty();
});

test('la clave escrita a mano no se devuelve en la respuesta', function () {
    comoAdministrador();
    $conjunto = unConjunto();

    $r = $this->postJson("/v1/admin/complexes/{$conjunto->complex_id}/owner", [
        'name'     => 'Administración',
        'email'    => 'mano@ejemplo.com',
        'password' => 'unaClaveLarga123',
    ])->assertCreated();

    // Quien la escribió ya la tiene; devolverla solo la deja en un registro más.
    expect($r->json('password'))->toBeNull();

    // Contra el hash y no con `auth()->attempt`: el guard de estas pruebas es
    // el de Sanctum por token, que no tiene `attempt`.
    expect(Hash::check('unaClaveLarga123', User::where('email', 'mano@ejemplo.com')->value('password')))
        ->toBeTrue();
});

test('el administrador asignado entra al panel de aliados y ve SU conjunto', function () {
    comoAdministrador();
    $suyo  = unConjunto('Los Almendros');
    $ajeno = unConjunto('Villa Carolina');

    $this->postJson("/v1/admin/complexes/{$suyo->complex_id}/owner", [
        'name'     => 'Administración Los Almendros',
        'email'    => 'almendros@ejemplo.com',
        'password' => 'unaClaveLarga123',
    ])->assertCreated();

    /*
     * La prueba que de verdad importa: que la cuenta recién creada FUNCIONE del
     * otro lado. Todo lo anterior se podía cumplir con una cuenta muda.
     */
    Sanctum::actingAs(User::where('email', 'almendros@ejemplo.com')->first());

    $mio = $this->getJson('/v1/conjunto/me')->assertOk()->json();

    expect(data_get($mio, 'complex.complex_id') ?? data_get($mio, 'complex_id'))
        ->toBe($suyo->complex_id);

    // Y que el conjunto del vecino le sigue siendo invisible: el alcance sale
    // de su ficha, nunca de un parámetro.
    expect(json_encode($mio))->not->toContain($ajeno->name);
});

test('nombrar a otro administrador baja al anterior a celador', function () {
    comoAdministrador();
    $conjunto = unConjunto();

    $this->postJson("/v1/admin/complexes/{$conjunto->complex_id}/owner", [
        'name' => 'Primero', 'email' => 'primero@ejemplo.com',
    ])->assertCreated();

    $this->postJson("/v1/admin/complexes/{$conjunto->complex_id}/owner", [
        'name' => 'Segundo', 'email' => 'segundo@ejemplo.com',
    ])->assertCreated();

    $primero = ComplexStaff::where('user_id', User::where('email', 'primero@ejemplo.com')->value('user_id'))->first();
    $segundo = ComplexStaff::where('user_id', User::where('email', 'segundo@ejemplo.com')->value('user_id'))->first();

    /*
     * No se le quita el acceso de golpe: el administrador saliente suele seguir
     * trabajando ahí, y dejarlo fuera sin avisar deja al conjunto sin portería
     * el mismo día del cambio. Baja a celador, que es reversible.
     */
    expect($primero->role)->toBe(ComplexStaff::CELADOR)
        ->and($segundo->role)->toBe(ComplexStaff::DUENO);

    // Y el conjunto tiene exactamente un administrador, no dos.
    expect(ComplexStaff::where('complex_id', $conjunto->complex_id)
        ->where('role', ComplexStaff::DUENO)->count())->toBe(1);
});

test('reponer el acceso del mismo administrador no lo duplica', function () {
    comoAdministrador();
    $conjunto = unConjunto();

    $this->postJson("/v1/admin/complexes/{$conjunto->complex_id}/owner", [
        'name' => 'Administración', 'email' => 'repone@ejemplo.com',
    ])->assertCreated();

    // Perdió la clave. Se vuelve a mandar el mismo correo: repone, no falla.
    $this->postJson("/v1/admin/complexes/{$conjunto->complex_id}/owner", [
        'name' => 'Administración', 'email' => 'repone@ejemplo.com',
    ])->assertOk();

    expect(User::where('email', 'repone@ejemplo.com')->count())->toBe(1)
        ->and(ComplexStaff::where('complex_id', $conjunto->complex_id)->count())->toBe(1);
});

/* ===================================================================== */
/*  LO QUE NO SE PUEDE HACER                                             */
/* ===================================================================== */

test('no convierte en administrador el correo de un comprador', function () {
    comoAdministrador();
    $conjunto = unConjunto();

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $comprador = User::factory()->create(['rol' => 1, 'email' => 'vecina@ejemplo.com']);

    /*
     * Convertirla le arrancaría su perfil de compradora y sus pedidos quedarían
     * colgando de un rol que ya no puede verlos. Es un error del que escribe el
     * correo, y hay que decírselo en vez de destruir una cuenta en silencio.
     */
    $this->postJson("/v1/admin/complexes/{$conjunto->complex_id}/owner", [
        'name' => 'Administración', 'email' => 'vecina@ejemplo.com',
    ])->assertStatus(422);

    expect((int) $comprador->fresh()->rol)->toBe(1)
        ->and(ComplexStaff::where('complex_id', $conjunto->complex_id)->count())->toBe(0);
});

test('quien solo consulta no puede nombrar administrador', function () {
    comoAdministrador(Area::NIVEL_CONSULTA);
    $conjunto = unConjunto();

    $this->getJson("/v1/admin/complexes/{$conjunto->complex_id}/staff")->assertOk();

    $this->postJson("/v1/admin/complexes/{$conjunto->complex_id}/owner", [
        'name' => 'Administración', 'email' => 'nadie@ejemplo.com',
    ])->assertForbidden();

    expect(User::where('email', 'nadie@ejemplo.com')->exists())->toBeFalse();
});

test('un conjunto que no existe responde 404 y no crea nada', function () {
    comoAdministrador();

    $this->postJson('/v1/admin/complexes/999999/owner', [
        'name' => 'Administración', 'email' => 'fantasma@ejemplo.com',
    ])->assertNotFound();

    expect(User::where('email', 'fantasma@ejemplo.com')->exists())->toBeFalse();
});

/* ===================================================================== */
/*  LA PANTALLA DE USUARIOS TAMPOCO PUEDE CREAR CUENTAS MUDAS            */
/* ===================================================================== */

test('crear un usuario de conjunto sin decir cual conjunto se rechaza', function () {
    comoAdministrador();

    /*
     * El `match` de `storeUser` tenía `default => null`: un rol 5 se creaba SIN
     * ficha y en silencio. La cuenta existía, iniciaba sesión y no veía nada.
     * Ahora el conjunto es obligatorio para los dos roles de conjunto.
     */
    $this->postJson('/v1/admin/users', [
        'name'     => 'Administración',
        'email'    => 'muda@ejemplo.com',
        'password' => 'unaClaveLarga123',
        'rol'      => ComplexStaff::ROL_DUENO,
    ])->assertStatus(422)->assertJsonValidationErrors('complex_id');

    expect(User::where('email', 'muda@ejemplo.com')->exists())->toBeFalse();
});

test('crear un celador desde usuarios lo ata a su conjunto', function () {
    comoAdministrador();
    $conjunto = unConjunto();

    $this->postJson('/v1/admin/users', [
        'name'       => 'Celador de turno',
        'email'      => 'celador@ejemplo.com',
        'password'   => 'unaClaveLarga123',
        'rol'        => ComplexStaff::ROL_CELADOR,
        'complex_id' => $conjunto->complex_id,
    ])->assertCreated();

    $ficha = ComplexStaff::where('user_id', User::where('email', 'celador@ejemplo.com')->value('user_id'))->first();

    expect($ficha)->not->toBeNull()
        ->and($ficha->complex_id)->toBe($conjunto->complex_id)
        ->and($ficha->role)->toBe(ComplexStaff::CELADOR);
});

test('los roles de siempre no piden conjunto', function () {
    comoAdministrador();

    // Un comprador no pertenece a ningún conjunto por el hecho de existir, y
    // exigírselo rompería la pantalla de usuarios para el 99 % de las altas.
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $this->postJson('/v1/admin/users', [
        'name'     => 'Vecina',
        'email'    => 'vecina2@ejemplo.com',
        'password' => 'unaClaveLarga123',
        'rol'      => 1,
    ])->assertCreated();

    expect(DB::table('buyer')->where('user_id', User::where('email', 'vecina2@ejemplo.com')->value('user_id'))->exists())
        ->toBeTrue();
});

/* ===================================================================== */
/*  LEER EL PERSONAL                                                     */
/* ===================================================================== */

test('el personal del conjunto separa al administrador de los celadores', function () {
    comoAdministrador();
    $conjunto = unConjunto();

    $this->postJson("/v1/admin/complexes/{$conjunto->complex_id}/owner", [
        'name' => 'Administración', 'email' => 'admin-conj@ejemplo.com',
    ])->assertCreated();

    $this->postJson('/v1/admin/users', [
        'name' => 'Celador', 'email' => 'cel@ejemplo.com',
        'password' => 'unaClaveLarga123',
        'rol' => ComplexStaff::ROL_CELADOR, 'complex_id' => $conjunto->complex_id,
    ])->assertCreated();

    $r = $this->getJson("/v1/admin/complexes/{$conjunto->complex_id}/staff")->assertOk()->json();

    expect($r['dueno']['email'])->toBe('admin-conj@ejemplo.com')
        ->and($r['celadores'])->toHaveCount(1)
        ->and($r['celadores'][0]['email'])->toBe('cel@ejemplo.com');

    // Y la lista de conjuntos refleja lo mismo, que es lo que ve quien no abre
    // la ficha.
    $fila = collect($this->getJson('/v1/admin/complexes')->json())
        ->firstWhere('complex_id', $conjunto->complex_id);

    expect($fila['admin_email'])->toBe('admin-conj@ejemplo.com')
        ->and($fila['guards_count'])->toBe(1);
});

/* ===================================================================== */
/*  NO SE LE QUITA EL ADMINISTRADOR A OTRO CONJUNTO                      */
/* ===================================================================== */

test('nombrar aca a quien ya administra otro conjunto se rechaza', function () {
    comoAdministrador();

    $suyo  = unConjunto('Villa Carolina');
    $otro  = unConjunto('Los Mangos');

    $this->postJson("/v1/admin/complexes/{$suyo->complex_id}/owner", [
        'name' => 'Administración', 'email' => 'admin@ejemplo.com',
    ])->assertCreated();

    /*
     * `user_id` es único en `complex_staff`, así que nombrarlo acá lo MOVERÍA:
     * Villa Carolina se quedaría sin nadie que la gestione ni registre entradas
     * en la portería, y sin que nada lo dijera. El aviso llega antes.
     */
    $r = $this->postJson("/v1/admin/complexes/{$otro->complex_id}/owner", [
        'name' => 'Administración', 'email' => 'admin@ejemplo.com',
    ])->assertStatus(422);

    expect($r->json('message'))->toContain('Villa Carolina');

    // Y sigue donde estaba.
    $ficha = ComplexStaff::where('user_id', User::where('email', 'admin@ejemplo.com')->value('user_id'))->first();
    expect((int) $ficha->complex_id)->toBe((int) $suyo->complex_id);
});

test('a un celador de otro conjunto si se le puede: el suyo no se queda sin cabeza', function () {
    comoAdministrador();

    $suyo = unConjunto('Villa Carolina');
    $otro = unConjunto('Los Mangos');

    $this->postJson('/v1/admin/users', [
        'name' => 'Celador', 'email' => 'cel@ejemplo.com',
        'password' => 'unaClaveLarga123',
        'rol' => ComplexStaff::ROL_CELADOR, 'complex_id' => $suyo->complex_id,
    ])->assertCreated();

    // 200 y no 201: la cuenta ya existía, lo que se creó es su vínculo nuevo.
    $this->postJson("/v1/admin/complexes/{$otro->complex_id}/owner", [
        'name' => 'Celador Ascendido', 'email' => 'cel@ejemplo.com',
    ])->assertOk();

    $ficha = ComplexStaff::where('user_id', User::where('email', 'cel@ejemplo.com')->value('user_id'))->first();
    expect((int) $ficha->complex_id)->toBe((int) $otro->complex_id)
        ->and($ficha->role)->toBe(ComplexStaff::DUENO);
});

test('al administrador anterior le baja tambien el rol, no solo la ficha', function () {
    comoAdministrador();
    $conjunto = unConjunto();

    $this->postJson("/v1/admin/complexes/{$conjunto->complex_id}/owner", [
        'name' => 'Primera', 'email' => 'primera@ejemplo.com',
    ])->assertCreated();

    $this->postJson("/v1/admin/complexes/{$conjunto->complex_id}/owner", [
        'name' => 'Segunda', 'email' => 'segunda@ejemplo.com',
    ])->assertCreated();

    $anterior = User::where('email', 'primera@ejemplo.com')->first();

    /*
     * Su ficha baja a celador —sigue trabajando ahí y quitarle la entrada de
     * golpe deja al conjunto sin portería el mismo día del cambio— y su rol de
     * usuario baja con ella. Si no, quedaba con `rol = 5` haciendo de celador y
     * el panel lo seguía enseñando como «Admin. de conjunto».
     */
    expect((int) $anterior->rol)->toBe(ComplexStaff::ROL_CELADOR);
    expect(ComplexStaff::de($anterior->user_id)->role)->toBe(ComplexStaff::CELADOR);
});

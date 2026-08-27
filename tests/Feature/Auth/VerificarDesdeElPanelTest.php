<?php

/**
 * DAR POR BUENO UN CORREO DESDE EL PANEL.
 *
 * Durante las pruebas se dan de alta cuentas desde la app —un domiciliario de
 * prueba, un comprador de prueba— con correos de usar y tirar a los que nunca
 * va a llegar nada. Sin esto quedan bloqueadas al iniciar sesión y hay que
 * entrar a la base de datos a mano.
 *
 * Lo que cuesta es el significado: `email_verified_at` dejaba de querer decir
 * «esta dirección existe y es suya» para querer decir «alguien lo dio por
 * bueno». Por eso casi todo lo de acá comprueba lo mismo desde ángulos
 * distintos: que las dos cosas SIGAN distinguiéndose.
 *
 *   `email_verified_by` nulo      la persona abrió su enlace. El correo existe.
 *   `email_verified_by` con valor un administrador lo afirmó. No está probado.
 */

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Alguien del área de sistema: tiene todos los módulos por definición.
 *
 * El nombre lleva sufijo porque los ayudantes de Pest son GLOBALES: ya existe
 * un `comoPersonal()` en las pruebas de mantenimiento y declarar otro tumbaba
 * la suite entera con un error fatal de PHP.
 */
function comoDelPanel(string $nivel = Area::NIVEL_GESTOR): User
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

/** Una cuenta recién registrada desde la app: existe y no ha confirmado nada. */
function sinVerificar(array $extra = []): User
{
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    return User::factory()->create(array_merge([
        'rol'                           => 3,
        'email_verified_at'             => null,
        'email_verified_by'             => null,
        'email_verification_token'      => Str::random(60),
        'email_verification_expires_at' => Carbon::now()->addHour(),
    ], $extra));
}

/* ===================================================================== */
/*  VERIFICARLO A MANO                                                   */
/* ===================================================================== */

test('el panel puede dar por bueno un correo que nunca se abrio', function () {
    $admin = comoDelPanel();
    $domi  = sinVerificar(['email' => 'domi.prueba@ejemplo.com']);

    $this->postJson("/v1/admin/users/{$domi->user_id}/verify-email")->assertOk();

    $domi->refresh();

    expect($domi->email_verified_at)->not->toBeNull()
        // Y queda dicho QUIÉN: es lo único que distingue esto de una
        // verificación de verdad.
        ->and($domi->email_verified_by)->toBe($admin->user_id)
        // El enlace pendiente se apaga: ya no hay nada que confirmar.
        ->and($domi->email_verification_expires_at)->toBeNull();
});

test('quien abrio su propio enlace no queda marcado como manual', function () {
    $domi = sinVerificar();

    /*
     * El recorrido real del correo, sin pasar por el panel. Es la mitad que da
     * sentido a la otra: si esto también dejara autor, la columna no
     * distinguiría nada.
     */
    $this->get('/verify-email?token=' . $domi->email_verification_token)
        ->assertOk();

    $domi->refresh();

    expect($domi->email_verified_at)->not->toBeNull()
        ->and($domi->email_verified_by)->toBeNull();
});

test('el listado deja ver cual de las dos cosas paso', function () {
    $admin = comoDelPanel();

    $aMano = sinVerificar(['email' => 'a.mano@ejemplo.com']);
    $solo  = sinVerificar(['email' => 'por.correo@ejemplo.com']);

    $this->postJson("/v1/admin/users/{$aMano->user_id}/verify-email")->assertOk();
    $this->get('/verify-email?token=' . $solo->email_verification_token)->assertOk();

    $filas = collect($this->getJson('/v1/admin/users')->assertOk()->json('data'));

    $uno = $filas->firstWhere('email', 'a.mano@ejemplo.com');
    $dos = $filas->firstWhere('email', 'por.correo@ejemplo.com');

    // El nombre de quien lo hizo viaja con la fila: el panel lo pinta sin
    // tener que pedir cada usuario aparte.
    expect($uno['email_verified_by'])->toBe($admin->user_id)
        ->and($uno['email_verified_by_name'])->toBe($admin->name)
        ->and($dos['email_verified_by'])->toBeNull()
        ->and($dos['email_verified_at'])->not->toBeNull();
});

test('el resumen cuenta aparte las que se dieron por buenas a mano', function () {
    comoDelPanel();

    $a = sinVerificar();
    sinVerificar(); // esta se queda sin verificar

    $this->postJson("/v1/admin/users/{$a->user_id}/verify-email")->assertOk();

    // `ListadoPaginado` publica los indicadores bajo `summary`.
    $resumen = $this->getJson('/v1/admin/users')->assertOk()->json('summary');

    expect($resumen['aMano'])->toBe(1)
        ->and($resumen['sinVerificar'])->toBe(1);
});

/* ===================================================================== */
/*  LO QUE NO SE PUEDE HACER                                             */
/* ===================================================================== */

test('no sobrescribe una verificacion que ya era buena', function () {
    comoDelPanel();
    $domi = sinVerificar();

    $this->get('/verify-email?token=' . $domi->email_verification_token)->assertOk();

    /*
     * Convertir una verificación comprobada en una afirmada por alguien es una
     * pérdida de información que no se puede deshacer. Se responde que ya
     * estaba y no se toca nada.
     */
    $this->postJson("/v1/admin/users/{$domi->user_id}/verify-email")
        ->assertStatus(422);

    expect($domi->fresh()->email_verified_by)->toBeNull();
});

test('quien solo consulta no puede verificar correos', function () {
    comoDelPanel(Area::NIVEL_CONSULTA);
    $domi = sinVerificar();

    // Ve el listado —incluido el estado del correo— y no puede afirmarlo.
    $this->getJson('/v1/admin/users')->assertOk();

    $this->postJson("/v1/admin/users/{$domi->user_id}/verify-email")
        ->assertForbidden();

    expect($domi->fresh()->email_verified_at)->toBeNull();
});

test('un usuario que no existe responde 404', function () {
    comoDelPanel();

    $this->postJson('/v1/admin/users/999999/verify-email')->assertNotFound();
});

test('sin sesion no se verifica nada', function () {
    $domi = sinVerificar();

    $this->postJson("/v1/admin/users/{$domi->user_id}/verify-email")
        ->assertUnauthorized();

    expect($domi->fresh()->email_verified_at)->toBeNull();
});

/* ===================================================================== */
/*  QUE SIRVA PARA LO QUE SE HIZO                                        */
/* ===================================================================== */

test('el domiciliario de prueba ya puede iniciar sesion', function () {
    comoDelPanel();

    $domi = sinVerificar([
        'email'    => 'domi.prueba@ejemplo.com',
        'password' => bcrypt('unaClaveLarga123'),
        'state'    => 1,
    ]);

    /*
     * ESTA es la prueba que justifica todo lo demás. El login rechaza cuentas
     * sin verificar, así que antes de esto una cuenta de prueba creada desde la
     * app se quedaba fuera y había que tocar la base a mano.
     */
    $this->postJson('/v1/login', [
        'email'    => 'domi.prueba@ejemplo.com',
        'password' => 'unaClaveLarga123',
    ])->assertStatus(403);

    $this->postJson("/v1/admin/users/{$domi->user_id}/verify-email")->assertOk();

    $this->postJson('/v1/login', [
        'email'    => 'domi.prueba@ejemplo.com',
        'password' => 'unaClaveLarga123',
    ])->assertOk();
});

test('queda registrado en la auditoria con su autor', function () {
    /*
     * `audit.console` viene en `false`, y las pruebas corren en consola: sin
     * esto el paquete no anota nada y la prueba pasaria en verde sin haber
     * comprobado el rastro. En una peticion web de verdad si se anota.
     */
    config(['audit.console' => true]);

    $admin = comoDelPanel();
    $domi  = sinVerificar();

    $this->postJson("/v1/admin/users/{$domi->user_id}/verify-email")->assertOk();

    /*
     * Va por Eloquent y no por el constructor de consultas justamente para
     * esto: el cambio queda con fecha y con autor sin tener que escribirlo a
     * mano en ningún sitio.
     */
    $rastro = \OwenIt\Auditing\Models\Audit::where('auditable_type', User::class)
        ->where('auditable_id', $domi->user_id)
        ->latest('id')
        ->first();

    expect($rastro)->not->toBeNull()
        ->and($rastro->user_id)->toBe($admin->user_id)
        ->and($rastro->new_values)->toHaveKey('email_verified_at');
});

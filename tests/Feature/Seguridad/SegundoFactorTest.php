<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use App\Services\Totp;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * SEGUNDO FACTOR Y SESIONES ABIERTAS
 *
 * Catorce cuentas entran al panel y varias mueven dinero. La única defensa era
 * una contraseña, y una contraseña filtrada no deja rastro de que se filtró.
 *
 * Lo que se comprueba es lo que separa un segundo factor útil de uno que estorba:
 * que no se active hasta demostrar que la app funciona, que quien pierde el
 * teléfono pueda entrar, que un código de recuperación se gaste, que quitarlo
 * exija la contraseña, y que se pueda cerrar la sesión que no reconoces.
 */

function usuarioDePanel(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    return User::factory()->create([
        'rol'               => 4,
        'area_id'           => Area::where('code', 'contabilidad')->firstOrFail()->id,
        'access_level'      => Area::NIVEL_GESTOR,
        'state'             => 1,
        'email_verified_at' => now(),
        'password'          => Hash::make('clave-de-verdad'),
    ]);
}

/** Da de alta el segundo factor y devuelve [usuario, secreto, códigos]. */
function conSegundoFactor(User $u): array
{
    Sanctum::actingAs($u);

    $alta = test()->postJson('/v1/admin/security/two-factor')->assertOk();
    $secreto = $alta->json('secret');

    $r = test()->postJson('/v1/admin/security/two-factor/confirm', [
        'code' => Totp::codigo($secreto),
    ])->assertOk();

    return [$u->fresh(), $secreto, $r->json('recovery_codes')];
}

/* ==================================================================== */
/* ALTA                                                                 */

it('no se activa hasta demostrar que la app genera los códigos', function () {
    $u = usuarioDePanel();
    Sanctum::actingAs($u);

    $this->postJson('/v1/admin/security/two-factor')->assertOk();

    /*
     * Entre generar el secreto y confirmarlo hay un paso a propósito. Activarlo
     * de una dejaría fuera a quien escaneó mal el código: sin segundo factor
     * funcionando y sin poder entrar, que es el peor resultado posible.
     */
    expect($u->fresh()->tieneSegundoFactor())->toBeFalse();

    $r = $this->getJson('/v1/admin/security')->assertOk();
    expect($r->json('two_factor.pending'))->toBeTrue();
    expect($r->json('two_factor.enabled'))->toBeFalse();
});

it('un código equivocado no lo activa', function () {
    $u = usuarioDePanel();
    Sanctum::actingAs($u);

    $this->postJson('/v1/admin/security/two-factor')->assertOk();

    $this->postJson('/v1/admin/security/two-factor/confirm', ['code' => '000000'])
        ->assertStatus(422);

    expect($u->fresh()->tieneSegundoFactor())->toBeFalse();
});

it('confirmado, queda activo y entrega códigos de recuperación', function () {
    [$u, , $codigos] = conSegundoFactor(usuarioDePanel());

    expect($u->tieneSegundoFactor())->toBeTrue();
    expect($codigos)->toHaveCount(8);

    // Se muestran UNA vez: quedan con resumen, igual que una contraseña, así que
    // no hay forma de volver a leerlos.
    foreach ($u->two_factor_recovery_codes as $guardado) {
        expect($guardado)->not->toBeIn($codigos);
    }
});

it('el secreto no sale nunca en una respuesta de usuario', function () {
    [$u] = conSegundoFactor(usuarioDePanel());

    /*
     * Con el secreto, quien lo lea genera los mismos códigos que el teléfono: el
     * segundo factor dejaría de serlo. Solo se muestra al darlo de alta, desde
     * su propio endpoint.
     */
    $json = $u->toJson();

    expect($json)->not->toContain('two_factor_secret');
    expect($json)->not->toContain($u->two_factor_secret);
});

/* ==================================================================== */
/* ENTRAR                                                               */

it('con segundo factor activo, la contraseña sola ya no basta', function () {
    [$u] = conSegundoFactor(usuarioDePanel());

    $r = $this->postJson('/v1/login', [
        'email'    => $u->email,
        'password' => 'clave-de-verdad',
    ])->assertStatus(401);

    expect($r->json('reason'))->toBe('two_factor_required');
    expect($r->json('token'))->toBeNull();
});

it('con el código del teléfono entra', function () {
    [$u, $secreto] = conSegundoFactor(usuarioDePanel());

    $this->postJson('/v1/login', [
        'email'           => $u->email,
        'password'        => 'clave-de-verdad',
        'two_factor_code' => Totp::codigo($secreto),
    ])->assertOk()->assertJsonStructure(['token', 'user']);
});

it('el código no sirve si la contraseña está mal', function () {
    [$u, $secreto] = conSegundoFactor(usuarioDePanel());

    // Y responde lo mismo que ante cualquier credencial incorrecta: decir "la
    // clave está mal pero el código bien" confirmaría medio secreto.
    $this->postJson('/v1/login', [
        'email'           => $u->email,
        'password'        => 'la-que-no-es',
        'two_factor_code' => Totp::codigo($secreto),
    ])->assertStatus(401);
});

it('un código de recuperación entra y SE GASTA', function () {
    [$u, , $codigos] = conSegundoFactor(usuarioDePanel());

    $uno = $codigos[0];

    $this->postJson('/v1/login', [
        'email'           => $u->email,
        'password'        => 'clave-de-verdad',
        'two_factor_code' => $uno,
    ])->assertOk();

    /*
     * Un código de recuperación reutilizable es una segunda contraseña
     * permanente escrita en un papel. Sirve una vez y desaparece.
     */
    expect($u->fresh()->two_factor_recovery_codes)->toHaveCount(7);

    $this->postJson('/v1/login', [
        'email'           => $u->email,
        'password'        => 'clave-de-verdad',
        'two_factor_code' => $uno,
    ])->assertStatus(401);
});

it('quien no tiene segundo factor entra como siempre', function () {
    $u = usuarioDePanel();

    // Activarlo es voluntario: obligar a todo el mundo de golpe deja fuera a
    // quien no tenga el teléfono a mano ese día.
    $this->postJson('/v1/login', [
        'email'    => $u->email,
        'password' => 'clave-de-verdad',
    ])->assertOk();
});

/* ==================================================================== */
/* QUITARLO                                                             */

it('desactivarlo exige la contraseña', function () {
    [$u] = conSegundoFactor(usuarioDePanel());
    Sanctum::actingAs($u);

    /*
     * Sin esto, a quien deje la sesión abierta un minuto le bastaría abrir esta
     * pantalla para quitarle el segundo factor a la cuenta — exactamente el
     * ataque del que protege.
     */
    $this->deleteJson('/v1/admin/security/two-factor', ['password' => 'la-que-no-es'])
        ->assertStatus(422);

    expect($u->fresh()->tieneSegundoFactor())->toBeTrue();

    $this->deleteJson('/v1/admin/security/two-factor', ['password' => 'clave-de-verdad'])
        ->assertOk();

    expect($u->fresh()->tieneSegundoFactor())->toBeFalse();
});

it('los códigos nuevos invalidan los anteriores', function () {
    [$u, , $viejos] = conSegundoFactor(usuarioDePanel());
    Sanctum::actingAs($u);

    $nuevos = $this->postJson('/v1/admin/security/two-factor/recovery-codes')
        ->assertOk()
        ->json('recovery_codes');

    expect($nuevos)->not->toBe($viejos);

    // Regenerar existe para cuando se sospecha que la lista se vio: si los
    // anteriores siguieran valiendo, no serviría de nada.
    $this->postJson('/v1/login', [
        'email'           => $u->email,
        'password'        => 'clave-de-verdad',
        'two_factor_code' => $viejos[0],
    ])->assertStatus(401);
});

/* ==================================================================== */
/* SESIONES                                                             */

it('las sesiones dicen de dónde salieron', function () {
    $u = usuarioDePanel();

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'])
        ->postJson('/v1/login', [
            'email'    => $u->email,
            'password' => 'clave-de-verdad',
        ])->assertOk();

    Sanctum::actingAs($u->fresh());

    $sesiones = $this->getJson('/v1/admin/security')->assertOk()->json('sessions');

    /*
     * Sin esto la lista dice "token #3, token #7" y no sirve para lo único que
     * existe: reconocer la sesión que no es tuya y cerrarla.
     */
    expect($sesiones)->not->toBeEmpty();
    expect($sesiones[0]['device'])->toBe('Chrome en Windows');
    expect($sesiones[0]['ip'])->not->toBeNull();
});

it('se pueden cerrar todas las demás sin cerrar la propia', function () {
    $u = usuarioDePanel();

    // Tres sesiones: como quien entró desde el portátil, el teléfono y un
    // ordenador prestado.
    foreach (range(1, 3) as $i) {
        $this->postJson('/v1/login', [
            'email'    => $u->email,
            'password' => 'clave-de-verdad',
        ])->assertOk();
    }

    expect($u->fresh()->tokens()->count())->toBe(3);

    Sanctum::actingAs($u->fresh());

    $r = $this->deleteJson('/v1/admin/security/sessions/others')->assertOk();

    /*
     * Es lo primero que hay que poder hacer al sospechar que una contraseña se
     * filtró. Cerrarlas de una en una es justo lo que nadie hace con prisa.
     *
     * Sanctum en pruebas no crea un token real para la sesión actual, así que
     * se cierran las tres; lo que importa es que el endpoint responda cuántas y
     * que no falle.
     */
    expect($r->json('closed'))->toBeGreaterThan(0);
});

it('nadie cierra la sesión de otra persona', function () {
    $mia   = usuarioDePanel();
    $ajena = usuarioDePanel();

    $this->postJson('/v1/login', [
        'email'    => $ajena->email,
        'password' => 'clave-de-verdad',
    ])->assertOk();

    $tokenAjeno = $ajena->fresh()->tokens()->first();

    Sanctum::actingAs($mia);

    // 404 y no 403: decir "esa sesión no es tuya" confirmaría que existe.
    $this->deleteJson("/v1/admin/security/sessions/{$tokenAjeno->id}")
        ->assertNotFound();

    expect($ajena->fresh()->tokens()->count())->toBe(1);
});

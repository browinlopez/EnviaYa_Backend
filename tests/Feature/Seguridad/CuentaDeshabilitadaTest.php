<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Una cuenta deshabilitada deja de valer en TODO el API, no solo al entrar.
 *
 * `user.state` se miraba al iniciar sesión y en la puerta del panel del
 * conjunto. El resto de rutas autenticadas aceptaba el token ya emitido, y los
 * de Sanctum no caducan solos.
 */
function tokenDe(User $usuario): string
{
    return $usuario->createToken('app')->plainTextToken;
}

test('una cuenta deshabilitada recibe 403 account_disabled con su token de siempre', function () {
    $usuario = User::factory()->create();
    $token = tokenDe($usuario);

    $this->withToken($token)->getJson('/v1/profile')->assertOk();

    $usuario->forceFill(['state' => 0])->save();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/v1/profile')
        ->assertStatus(403)
        ->assertJsonPath('reason', 'account_disabled');
});

test('el token con el que pidio queda borrado', function () {
    $usuario = User::factory()->create(['state' => 0]);
    tokenDe($usuario);
    $token = tokenDe($usuario);

    $this->withToken($token)->getJson('/v1/profile')->assertStatus(403);

    // Solo el que se uso: el otro telefono se cae en su propia peticion.
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $usuario->user_id)->count())->toBe(1);

    // Ya no autentica: aunque se rehabilite, hay que volver a iniciar sesion.
    $usuario->forceFill(['state' => 1])->save();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/v1/profile')->assertStatus(401);
});

test('aplica tambien a los paneles con su propia puerta (negocio, admin)', function () {
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'administrador', 'guard_name' => 'web']);
    $usuario = User::factory()->create(['state' => 0, 'rol' => 4]);
    $token = tokenDe($usuario);

    $this->withToken($token)->getJson('/v1/admin/users')
        ->assertStatus(403)
        ->assertJsonPath('reason', 'account_disabled');
});

test('cerrar sesion sigue funcionando con la cuenta deshabilitada', function () {
    $usuario = User::factory()->create(['state' => 0]);
    $token = tokenDe($usuario);

    $this->withToken($token)->postJson('/v1/logout')->assertOk();
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

test('las rutas publicas no se ven afectadas: el login sigue explicando el motivo', function () {
    User::factory()->create([
        'email' => 'apagada@ejemplo.test',
        'password' => bcrypt('Secreta-123'),
        'state' => 0,
    ]);

    $this->postJson('/v1/login', ['email' => 'apagada@ejemplo.test', 'password' => 'Secreta-123'])
        ->assertStatus(403)
        ->assertJsonPath('reason', 'account_disabled');
});

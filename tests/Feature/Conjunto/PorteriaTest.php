<?php

use App\Models\Conjunto\ComplexStaff;
use App\Models\Domiciliary;
use App\Models\Rol;
use App\Models\User;
use App\Services\AccesoAlConjunto;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * La portería de un conjunto.
 *
 * Lo que se fija acá es sobre todo el ALCANCE POR REGISTRO, que hasta ahora no
 * existía en ninguna parte del sistema: los permisos eran por módulo y nunca
 * por fila. La prueba que más importa es la última — un celador pidiendo la
 * portería de otro conjunto no recibe datos, recibe los suyos.
 */

function conjuntoConPersonal(string $rol = ComplexStaff::CELADOR): array
{
    foreach ([5 => 'dueno_conjunto', 6 => 'celador'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    $complexId = DB::table('residential_complexes')->insertGetId([
        'name' => 'Los Almendros', 'state' => 1, 'people_count' => 0,
        'towers_count' => 4, 'apartments_per_tower' => 20,
    ], 'complex_id');

    $user = User::factory()->create([
        'rol' => $rol === ComplexStaff::DUENO ? 5 : 6,
    ]);

    ComplexStaff::create([
        'user_id' => $user->user_id,
        'complex_id' => $complexId,
        'role' => $rol,
        'state' => true,
    ]);

    return compact('complexId', 'user');
}

function repartidorConPedidoEn(?int $complexId, string $cedula = '1090123456'): Domiciliary
{
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $u = User::factory()->create(['rol' => 3]);
    $domiId = DB::table('domiciliary')->insertGetId([
        'user_id' => $u->user_id, 'available' => 1, 'qualification' => 0,
        'state' => 1, 'document' => $cedula,
    ], 'domiciliary_id');

    if ($complexId) {
        $comprador = User::factory()->create(['rol' => 1]);
        $buyerId = DB::table('buyer')->insertGetId([
            'user_id' => $comprador->user_id, 'qualification' => 0,
            'belongs_to_complex' => 1, 'state' => 1,
        ]);

        $addressId = DB::table('user_address')->insertGetId([
            'user_id' => $comprador->user_id, 'address' => 'Calle 84',
            'complex_id' => $complexId, 'tower' => '3', 'apartment' => '502',
            'state' => 1,
        ]);

        $negocio = DB::table('business')->insertGetId([
            'name' => 'Tienda', 'qualification' => 0, 'state' => 1,
        ]);

        DB::table('orderssales')->insert([
            'buyer_id' => $buyerId, 'busines_id' => $negocio,
            'domiciliary_id' => $domiId, 'address_id' => $addressId,
            'subtotal' => 18000, 'domicilio' => 2000, 'total' => 20000,
            'sale_date' => now(), 'dispatched_at' => now(),
            'state' => 3, 'payment_state' => 'paid',
        ]);
    }

    return Domiciliary::find($domiId);
}

/* ---------------------------------------------------------------------- */

test('con codigo vigente y pedido en el conjunto, entra', function () {
    $c = conjuntoConPersonal();
    $domi = repartidorConPedidoEn($c['complexId']);

    $codigo = app(AccesoAlConjunto::class)->generar($domi)['code'];

    Sanctum::actingAs($c['user']);

    $r = $this->postJson('/v1/conjunto/porteria/verificar', ['code' => $codigo])
        ->assertOk()
        ->assertJsonPath('allowed', true);

    expect($r->json('orders'))->toHaveCount(1)
        ->and($r->json('orders.0.tower'))->toBe('3');

    // Y queda registrado: sin rastro la portería no sirve como control.
    expect(DB::table('complex_entries')->count())->toBe(1);
});

test('sin pedidos en el conjunto, el mensaje es exacto', function () {
    $c = conjuntoConPersonal();
    $domi = repartidorConPedidoEn(null); // sin ningún pedido

    $codigo = app(AccesoAlConjunto::class)->generar($domi)['code'];

    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/porteria/verificar', ['code' => $codigo])
        ->assertOk()
        ->assertJsonPath('allowed', false)
        ->assertJsonPath('message', 'Este domiciliario no tiene pedidos en el conjunto');

    // No se anota la entrada: alguien a quien no se dejó pasar ensuciaría el
    // historial.
    expect(DB::table('complex_entries')->count())->toBe(0);
});

test('un pedido a OTRO conjunto no da derecho a entrar', function () {
    $mio  = conjuntoConPersonal();
    $otro = conjuntoConPersonal();

    // Lleva pedido, pero al conjunto de al lado.
    $domi = repartidorConPedidoEn($otro['complexId']);
    $codigo = app(AccesoAlConjunto::class)->generar($domi)['code'];

    Sanctum::actingAs($mio['user']);

    $this->postJson('/v1/conjunto/porteria/verificar', ['code' => $codigo])
        ->assertOk()
        ->assertJsonPath('allowed', false);
});

test('un codigo vencido no sirve', function () {
    $c = conjuntoConPersonal();
    $domi = repartidorConPedidoEn($c['complexId']);

    $codigo = app(AccesoAlConjunto::class)->generar($domi)['code'];

    // Seis minutos después: el código dura cinco.
    $this->travel(6)->minutes();

    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/porteria/verificar', ['code' => $codigo])
        ->assertStatus(404)
        ->assertJsonPath('allowed', false);
});

test('generar un codigo nuevo invalida el anterior', function () {
    // Si valieran varios a la vez, uno filtrado seguiría sirviendo.
    $c = conjuntoConPersonal();
    $domi = repartidorConPedidoEn($c['complexId']);

    $acceso = app(AccesoAlConjunto::class);
    $viejo = $acceso->generar($domi)['code'];
    $acceso->generar($domi);

    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/porteria/verificar', ['code' => $viejo])
        ->assertStatus(404);
});

test('la cedula tambien sirve, y queda anotado que se uso', function () {
    // Un teléfono sin batería no puede dejar a nadie sin entregar.
    $c = conjuntoConPersonal();
    repartidorConPedidoEn($c['complexId'], '1090999888');

    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/porteria/verificar', ['document' => '1090999888'])
        ->assertOk()
        ->assertJsonPath('allowed', true);

    expect(DB::table('complex_entries')->value('method'))->toBe('cedula');
});

test('el celador solo ve las entradas de SU conjunto', function () {
    /*
     * La prueba que sostiene todo el alcance por registro. Hasta este cambio
     * los permisos eran por módulo: quien tenía la sección la tenía entera,
     * para todos los conjuntos del país.
     */
    $mio  = conjuntoConPersonal();
    $otro = conjuntoConPersonal();

    DB::table('complex_entries')->insert([
        ['complex_id' => $mio['complexId'], 'domiciliary_id' => repartidorConPedidoEn(null)->domiciliary_id,
         'method' => 'codigo', 'orders_count' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['complex_id' => $otro['complexId'], 'domiciliary_id' => repartidorConPedidoEn(null)->domiciliary_id,
         'method' => 'codigo', 'orders_count' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    Sanctum::actingAs($mio['user']);

    expect($this->getJson('/v1/conjunto/porteria/entradas')->assertOk()->json('data'))
        ->toHaveCount(1);
});

test('quien no es personal de conjunto no entra al panel', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $this->getJson('/v1/conjunto/porteria/entradas')->assertStatus(403);
});

test('un domiciliario pide su propio codigo, no el de otro', function () {
    $domi = repartidorConPedidoEn(null);

    Sanctum::actingAs($domi->user);

    $r = $this->postJson('/v1/domiciliaries/access-code')->assertOk();

    expect($r->json('code'))->toBeString()
        ->and($r->json('expires_in'))->toBe(AccesoAlConjunto::VIGENCIA_SEGUNDOS);

    // El código resuelve a ÉL, no a quien se pida por parámetro.
    expect(app(AccesoAlConjunto::class)->resolverCodigo($r->json('code'))->domiciliary_id)
        ->toBe($domi->domiciliary_id);
});

/* ------------------------------ EL PANEL ------------------------------ */

test('al entrar, el panel recibe su conjunto y sus permisos', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    $r = $this->getJson('/v1/conjunto/me')->assertOk();

    expect($r->json('complex.name'))->toBe('Los Almendros')
        ->and($r->json('role'))->toBe(ComplexStaff::DUENO)
        ->and($r->json('permissions.celadores.manage'))->toBeTrue();
});

test('el celador no administra celadores', function () {
    // Su trabajo es la puerta, no las cuentas.
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);
    Sanctum::actingAs($c['user']);

    expect($this->getJson('/v1/conjunto/me')->json('permissions.celadores'))->toBeNull();

    $this->getJson('/v1/conjunto/celadores')->assertStatus(403);
});

test('el dueno crea un celador de su conjunto', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/celadores', [
        'name' => 'Pedro Vigilante',
        'email' => 'pedro@ejemplo.test',
        'password' => 'ClaveDePrueba1*',
    ])->assertCreated();

    $ficha = ComplexStaff::whereHas('user', fn ($q) => $q->where('email', 'pedro@ejemplo.test'))->first();

    // Del conjunto de quien lo creó, tomado de la sesión.
    expect((int) $ficha->complex_id)->toBe($c['complexId'])
        ->and($ficha->role)->toBe(ComplexStaff::CELADOR);
});

test('el celador creado puede entrar y usar la porteria', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/celadores', [
        'name' => 'Pedro Vigilante',
        'email' => 'pedro@ejemplo.test',
        'password' => 'ClaveDePrueba1*',
    ])->assertCreated();

    $this->postJson('/v1/login', [
        'email' => 'pedro@ejemplo.test',
        'password' => 'ClaveDePrueba1*',
    ])->assertOk();

    Sanctum::actingAs(User::where('email', 'pedro@ejemplo.test')->first());
    $this->getJson('/v1/conjunto/porteria/entradas')->assertOk();
});

test('un dueno no puede tocar celadores de otro conjunto', function () {
    /*
     * La prueba que importa del alcance por registro en el lado de escritura.
     * Responde 404 y no 403 a propósito: confirmar que existe pero es de otro
     * ya sería decirle algo del edificio del vecino.
     */
    $mio  = conjuntoConPersonal(ComplexStaff::DUENO);
    $otro = conjuntoConPersonal(ComplexStaff::CELADOR);

    $ajeno = ComplexStaff::where('complex_id', $otro['complexId'])->first();

    Sanctum::actingAs($mio['user']);

    $this->putJson("/v1/conjunto/celadores/{$ajeno->id}", ['state' => false])
        ->assertStatus(404);

    expect($ajeno->fresh()->state)->toBeTrue();
});

test('el dueno ve cuanta gente de su conjunto usa la plataforma', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);

    // `user.rol` es clave foránea: sin la fila 1 el insert falla.
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    // Dos residentes en la torre 3, uno sin torre declarada.
    foreach ([['3'], ['3'], [null]] as [$torre]) {
        $u = User::factory()->create(['rol' => 1]);
        $buyerId = DB::table('buyer')->insertGetId([
            'user_id' => $u->user_id, 'qualification' => 0,
            'belongs_to_complex' => 1, 'state' => 1,
        ]);
        DB::table('buyer_complex')->insert([
            'buyer_id' => $buyerId, 'complex_id' => $c['complexId'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($torre) {
            DB::table('user_address')->insert([
                'user_id' => $u->user_id, 'address' => 'Calle 84',
                'complex_id' => $c['complexId'], 'tower' => $torre,
                'apartment' => '101', 'state' => 1,
            ]);
        }
    }

    Sanctum::actingAs($c['user']);

    $r = $this->getJson('/v1/conjunto/residentes')->assertOk();

    expect($r->json('total'))->toBe(3);
});

test('el celador no ve quien vive donde', function () {
    // Son datos personales de terceros: su relación es con la plataforma, no
    // con la portería.
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);
    Sanctum::actingAs($c['user']);

    $this->getJson('/v1/conjunto/residentes')->assertStatus(403);
});

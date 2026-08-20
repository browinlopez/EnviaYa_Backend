<?php

use App\Jobs\EnviarLotePush;
use App\Models\Area;
use App\Models\DeviceToken;
use App\Models\Marketing\PushCampaign;
use App\Models\Rol;
use App\Models\User;
use App\Services\Push\Notificador;
use App\Services\Push\ResultadoPush;
use App\Services\Push\TransportePush;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/**
 * NOTIFICACIONES A LOS TELÉFONOS
 *
 * El módulo cerraba campañas y no entregaba nada: resolvía el segmento, guardaba
 * a cuánta gente alcanzaba y ponía `sent_at`. El servidor lo decía
 * (`delivered: false`), pero cualquiera que usara la pantalla creía haber
 * enviado.
 *
 * Lo que se comprueba acá es lo que separa "cerrar una campaña" de "entregarla":
 * que el segmento se traduzca a DISPOSITIVOS y no a personas, que un token
 * muerto se marque y uno con un fallo de red no, que los contadores digan lo que
 * de verdad salió, y que sin transporte configurado la pantalla no mienta.
 */

/** Transporte de mentira, con el resultado que le digamos. */
class TransporteDePrueba implements TransportePush
{
    public array $enviados = [];

    public function __construct(
        private array $resultadoPorToken = [],
        private bool $listo = true,
    ) {
    }

    public function nombre(): string
    {
        return 'Transporte de prueba';
    }

    public function configurado(): bool
    {
        return $this->listo;
    }

    public function enviar(array $tokens, string $titulo, string $cuerpo, array $datos = []): array
    {
        $this->enviados[] = compact('tokens', 'titulo', 'cuerpo', 'datos');

        $salida = [];

        foreach ($tokens as $t) {
            $salida[$t] = $this->resultadoPorToken[$t] ?? ResultadoPush::entregado();
        }

        return $salida;
    }
}

function comoMarketing(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', 'marketing')->firstOrFail()->id,
        'access_level' => Area::NIVEL_GESTOR,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

function conTelefono(User $u, string $token, string $plataforma = 'android'): DeviceToken
{
    return DeviceToken::create([
        'user_id'  => $u->user_id,
        'token'    => $token,
        'platform' => $plataforma,
    ]);
}

function campanaDePrueba(array $segmento = []): PushCampaign
{
    return PushCampaign::create([
        'title'   => 'Dos por uno hoy',
        'body'    => 'Solo hasta las 6 p. m.',
        'segment' => $segmento,
        'state'   => PushCampaign::BORRADOR,
    ]);
}

/* ==================================================================== */
/* REGISTRO DEL DISPOSITIVO                                             */

it('la app registra su teléfono y es idempotente', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);
    $yo = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($yo);

    $this->postJson('/v1/devices', [
        'token'    => 'tok-abc',
        'platform' => 'android',
    ])->assertOk();

    // FCM rota los tokens y la app vuelve a registrarse en cada arranque: dos
    // filas para el mismo teléfono significarían dos notificaciones por persona.
    $this->postJson('/v1/devices', [
        'token'       => 'tok-abc',
        'platform'    => 'android',
        'app_version' => '2.4.1',
    ])->assertOk();

    expect(DeviceToken::count())->toBe(1);
    expect(DeviceToken::first()->app_version)->toBe('2.4.1');
});

it('un teléfono que cambia de dueño deja de ser del anterior', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);

    $antiguo = User::factory()->create(['rol' => 1]);
    $nuevo   = User::factory()->create(['rol' => 1]);

    Sanctum::actingAs($antiguo);
    $this->postJson('/v1/devices', ['token' => 'tok-xyz', 'platform' => 'ios'])->assertOk();

    Sanctum::actingAs($nuevo);
    $this->postJson('/v1/devices', ['token' => 'tok-xyz', 'platform' => 'ios'])->assertOk();

    /*
     * Alguien cierra sesión en su teléfono y entra otra persona: el token es el
     * mismo. Conservando la fila anterior, la promoción para compradores de
     * Soledad le llegaría al dueño anterior del aparato.
     */
    expect(DeviceToken::count())->toBe(1);
    expect((int) DeviceToken::first()->user_id)->toBe((int) $nuevo->user_id);
});

it('registrarse revive un token que se había dado por muerto', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);
    $yo = User::factory()->create(['rol' => 1]);

    conTelefono($yo, 'tok-revive')->update([
        'failed_at'   => now()->subDay(),
        'fail_reason' => 'UNREGISTERED',
    ]);

    Sanctum::actingAs($yo);
    $this->postJson('/v1/devices', ['token' => 'tok-revive', 'platform' => 'android'])->assertOk();

    // Pasa al reinstalar la app: el proveedor puede devolver el mismo token, y
    // sin esto quedaría descartado para siempre.
    expect(DeviceToken::first()->failed_at)->toBeNull();
});

it('cerrar sesión retira el teléfono sin marcarlo como fallido', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);
    $yo = User::factory()->create(['rol' => 1]);
    conTelefono($yo, 'tok-fuera');

    Sanctum::actingAs($yo);
    $this->deleteJson('/v1/devices', ['token' => 'tok-fuera'])->assertOk();

    // Se borra en vez de marcarse: cerrar sesión es una decisión de la persona,
    // no un fallo del token, y el registro de muertos existe para detectar
    // desinstalaciones.
    expect(DeviceToken::count())->toBe(0);
});

it('nadie puede retirar el teléfono de otro', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);
    $dueno = User::factory()->create(['rol' => 1]);
    $otro  = User::factory()->create(['rol' => 1]);
    conTelefono($dueno, 'tok-ajeno');

    Sanctum::actingAs($otro);
    $this->deleteJson('/v1/devices', ['token' => 'tok-ajeno'])->assertOk();

    // Contesta 200 y no borra nada: decir "ese token no es tuyo" confirmaría que
    // existe. Lo que importa es que siga ahí.
    expect(DeviceToken::count())->toBe(1);
});

/* ==================================================================== */
/* ENVÍO                                                                */

it('el segmento son personas y el envío son dispositivos', function () {
    Queue::fake();
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);

    $conDos = User::factory()->create(['rol' => 1, 'state' => 1]);
    conTelefono($conDos, 'tok-1');
    conTelefono($conDos, 'tok-2', 'ios');

    User::factory()->create(['rol' => 1, 'state' => 1]); // sin la app instalada

    comoMarketing();
    $c = campanaDePrueba(['roles' => [1]]);

    $r = $this->postJson("/v1/admin/marketing/push/{$c->id}/send")->assertOk();

    /*
     * Es la distinción que faltaba. "Llegó a 3.000 personas" es falso si solo
     * 800 tienen la app instalada con sesión abierta, y esa diferencia es
     * justamente lo que hay que poder ver.
     */
    expect($r->json('recipients'))->toBeGreaterThanOrEqual(2);
    expect($r->json('devices'))->toBe(2);

    Queue::assertPushed(EnviarLotePush::class, 1);
});

it('sin transporte configurado lo DICE en vez de fingir', function () {
    Queue::fake();
    comoMarketing();

    // El transporte de desarrollo reporta fallo, no éxito: uno de mentira que
    // dijera "entregado" reproduciría exactamente la mentira que se arregló.
    $c = campanaDePrueba();
    $r = $this->postJson("/v1/admin/marketing/push/{$c->id}/send")->assertOk();

    expect($r->json('delivered'))->toBeFalse();
    expect($r->json('note'))->toContain('FCM_CREDENTIALS');
});

it('parte el envío en lotes', function () {
    Queue::fake();
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);

    $u = User::factory()->create(['rol' => 1, 'state' => 1]);

    foreach (range(1, Notificador::POR_LOTE + 30) as $i) {
        conTelefono($u, "tok-{$i}");
    }

    comoMarketing();
    $c = campanaDePrueba(['roles' => [1]]);

    $this->postJson("/v1/admin/marketing/push/{$c->id}/send")->assertOk();

    // Firebase manda un mensaje por llamada: sin lotes, una campaña de mil
    // teléfonos sería un solo trabajo de mil peticiones HTTP que al fallar se
    // reintentaría entero.
    Queue::assertPushed(EnviarLotePush::class, 2);
});

it('cuenta lo entregado y marca solo los tokens muertos', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);
    $u = User::factory()->create(['rol' => 1, 'state' => 1]);

    conTelefono($u, 'bueno');
    conTelefono($u, 'desinstalado');
    conTelefono($u, 'sin-red');

    $transporte = new TransporteDePrueba([
        'desinstalado' => ResultadoPush::tokenMuerto('UNREGISTERED'),
        'sin-red'      => ResultadoPush::falloTemporal('HTTP 503'),
    ]);

    $c = campanaDePrueba();
    (new Notificador($transporte))->enviarLote($c, ['bueno', 'desinstalado', 'sin-red']);

    $c->refresh();

    expect((int) $c->delivered_count)->toBe(1);
    expect((int) $c->failed_count)->toBe(2);

    /*
     * La distinción que más cuesta si se hace mal: un token rechazado porque la
     * app se desinstaló no vuelve a servir nunca y hay que dejar de gastarle
     * envíos; un 503 del proveedor es pasajero y ese teléfono sigue existiendo.
     * Tratarlos igual significa o perder suscriptores buenos, o reintentar para
     * siempre contra teléfonos que ya no están.
     */
    expect(DeviceToken::where('token', 'desinstalado')->first()->failed_at)->not->toBeNull();
    expect(DeviceToken::where('token', 'sin-red')->first()->failed_at)->toBeNull();
    expect(DeviceToken::where('token', 'bueno')->first()->last_seen_at)->not->toBeNull();
});

it('no se le manda a un token ya marcado como muerto', function () {
    Queue::fake();
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);

    $u = User::factory()->create(['rol' => 1, 'state' => 1]);
    conTelefono($u, 'vivo');
    conTelefono($u, 'muerto')->update(['failed_at' => now()]);

    comoMarketing();
    $c = campanaDePrueba(['roles' => [1]]);

    $r = $this->postJson("/v1/admin/marketing/push/{$c->id}/send")->assertOk();

    expect($r->json('devices'))->toBe(1);
});

it('la notificación lleva a dónde ir al tocarla', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'buyer', 'guard_name' => 'web']);
    $u = User::factory()->create(['rol' => 1, 'state' => 1]);
    conTelefono($u, 'tok-enlace');

    $transporte = new TransporteDePrueba();

    $c = campanaDePrueba();
    $c->update(['link_type' => 'business', 'link_value' => '42']);

    (new Notificador($transporte))->enviarLote($c, ['tok-enlace']);

    // Misma convención que los banners: la app ya sabe interpretar este par.
    $datos = $transporte->enviados[0]['datos'];

    expect($datos['link_type'])->toBe('business');
    expect($datos['link_value'])->toBe('42');
});

it('el texto que sale es el de la campaña', function () {
    $transporte = new TransporteDePrueba();
    $c = campanaDePrueba();

    (new Notificador($transporte))->enviarLote($c, ['tok']);

    expect($transporte->enviados[0]['titulo'])->toBe('Dos por uno hoy');
    expect($transporte->enviados[0]['cuerpo'])->toBe('Solo hasta las 6 p. m.');
});

it('una campaña ya enviada no se manda dos veces', function () {
    Queue::fake();
    comoMarketing();

    $c = campanaDePrueba();
    $this->postJson("/v1/admin/marketing/push/{$c->id}/send")->assertOk();

    $this->postJson("/v1/admin/marketing/push/{$c->id}/send")->assertStatus(422);

    Queue::assertPushed(EnviarLotePush::class, 0); // sin dispositivos, ninguno
});

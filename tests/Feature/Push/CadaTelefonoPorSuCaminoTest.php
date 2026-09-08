<?php

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Push\PushApns;
use App\Services\Push\PushFirebase;
use App\Services\Push\PushSegunPlataforma;
use App\Services\Push\ResultadoPush;
use App\Services\Push\TransportePush;

/**
 * ANDROID POR FIREBASE, IPHONE POR APPLE.
 *
 * EL FALLO QUE ESTO CAZA, y que estuvo vivo desde que se montaron las
 * notificaciones: la app pide en iOS el token NATIVO de APNs y el servidor lo
 * metía en `message.token` de Firebase, que solo acepta los suyos. Los dos son
 * cadenas de hexadecimal, nadie se quejaba al guardarlo, el envío se aceptaba
 * y Firebase devolvía `INVALID_ARGUMENT`. Ningún iPhone recibió un aviso nunca,
 * y nada en la pantalla lo decía.
 *
 * Lo que se comprueba acá es el ENRUTADO, que es donde vuelve a colarse: si un
 * día alguien cambia la forma de elegir el camino, los avisos de iPhone se van
 * en silencio por Firebase otra vez.
 */

/** Un transporte de mentira que solo anota a quién le tocó. */
function transporteEspia(): object
{
    return new class {
        public array $recibidos = [];

        public function enviar(array $tokens, string $t, string $c, array $d = []): array
        {
            $this->recibidos = array_merge($this->recibidos, $tokens);

            return array_fill_keys($tokens, ResultadoPush::entregado());
        }

        public function configurado(): bool
        {
            return true;
        }

        public function nombre(): string
        {
            return 'espía';
        }
    };
}

/** Registra un teléfono con su plataforma, como hace la app al arrancar. */
function telefono(string $token, string $plataforma): string
{
    $user = User::factory()->create();

    /*
     * `failed_at` vacio es lo que marca un token vivo —lo dice
     * `DeviceToken::scopeVivos()`—. Antes aqui habia un `'state' => 1` que no
     * corresponde a ninguna columna: Eloquent descarta en silencio lo que no
     * esta en `$fillable`, asi que la prueba pasaba igual y dejaba escrito que
     * existia una columna inexistente. De ahi salio una consulta a mano contra
     * produccion que reviento con «Unknown column 'state'».
     */
    DeviceToken::create([
        'user_id'  => $user->user_id,
        'token'    => $token,
        'platform' => $plataforma,
    ]);

    return $token;
}

it('el token de un iPhone NO se manda a Firebase', function () {
    $ios = telefono(str_repeat('a', 64), 'ios');
    $android = telefono('token:de-firebase-android', 'android');

    $fcm = Mockery::mock(PushFirebase::class);
    $apns = Mockery::mock(PushApns::class);

    $apns->shouldReceive('configurado')->andReturn(true);

    /*
     * La expectativa es la prueba: Firebase recibe SOLO el de Android y Apple
     * SOLO el de iPhone. Si el enrutado se rompe, una de las dos falla.
     */
    $fcm->shouldReceive('enviar')
        ->once()
        ->with([$android], 'T', 'C', [])
        ->andReturn([$android => ResultadoPush::entregado()]);

    $apns->shouldReceive('enviar')
        ->once()
        ->with([$ios], 'T', 'C', [])
        ->andReturn([$ios => ResultadoPush::entregado()]);

    $router = new PushSegunPlataforma($fcm, $apns);
    $r = $router->enviar([$ios, $android], 'T', 'C');

    expect($r)->toHaveCount(2)
        ->and($r[$ios]->ok)->toBeTrue()
        ->and($r[$android]->ok)->toBeTrue();
});

it('un token que no está registrado va por Firebase', function () {
    $fcm = Mockery::mock(PushFirebase::class);
    $apns = Mockery::mock(PushApns::class);

    $apns->shouldReceive('configurado')->andReturn(true);

    // El respaldo importa: nunca se descarta un envío por no saber de dónde
    // salió el token —una prueba a mano, un comando—.
    $fcm->shouldReceive('enviar')
        ->once()
        ->with(['suelto'], 'T', 'C', [])
        ->andReturn(['suelto' => ResultadoPush::entregado()]);

    $apns->shouldNotReceive('enviar');

    (new PushSegunPlataforma($fcm, $apns))->enviar(['suelto'], 'T', 'C');
});

it('sin APNs configurado los iPhone fallan pero los Android siguen llegando', function () {
    $ios = telefono(str_repeat('b', 64), 'ios');
    $android = telefono('token:android-2', 'android');

    $fcm = Mockery::mock(PushFirebase::class);
    $apns = Mockery::mock(PushApns::class);

    $apns->shouldReceive('configurado')->andReturn(false);
    $apns->shouldNotReceive('enviar');

    $fcm->shouldReceive('enviar')
        ->once()
        ->with([$android], 'T', 'C', [])
        ->andReturn([$android => ResultadoPush::entregado()]);

    $r = (new PushSegunPlataforma($fcm, $apns))->enviar([$ios, $android], 'T', 'C');

    expect($r[$android]->ok)->toBeTrue()
        ->and($r[$ios]->ok)->toBeFalse()
        /*
         * TEMPORAL y no permanente: la culpa es del servidor, no del teléfono.
         * Marcarlo como muerto borraría el token y obligaría a reinstalar la
         * app para volver a recibir avisos el día que se configure APNs.
         */
        ->and($r[$ios]->permanente)->toBeFalse();
});

it('sin ningún teléfono no se llama a nadie', function () {
    $fcm = Mockery::mock(PushFirebase::class);
    $apns = Mockery::mock(PushApns::class);

    $fcm->shouldNotReceive('enviar');
    $apns->shouldNotReceive('enviar');

    expect((new PushSegunPlataforma($fcm, $apns))->enviar([], 'T', 'C'))->toBe([]);
});

it('el enrutador está enchufado de verdad cuando Firebase está configurado', function () {
    config([
        'services.fcm.project_id' => 'proyecto-de-prueba',
        // Una cuenta de servicio de mentira, solo para que `configurado()`
        // diga que sí: no se llega a firmar nada.
        'services.fcm.credentials_json' => base64_encode(json_encode([
            'type' => 'service_account',
            'project_id' => 'proyecto-de-prueba',
            'client_email' => 'x@y.iam.gserviceaccount.com',
            'private_key' => '-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----\n',
        ])),
    ]);

    /*
     * Esto es lo que impide que el enrutador quede construido y sin conectar,
     * que es el patrón que más veces ha aparecido en este proyecto.
     */
    expect(app(TransportePush::class))->toBeInstanceOf(PushSegunPlataforma::class);
});

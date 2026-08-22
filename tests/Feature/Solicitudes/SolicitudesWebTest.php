<?php

use App\Models\Operacion\LandingRequest;

/**
 * LO QUE LA WEB PÚBLICA PUEDE DEJAR GUARDADO
 *
 * Durante meses los seis formularios de la landing no guardaron nada: la capa
 * de envío estaba en modo demostración, así que validaban, respondían
 * "¡Listo!" y descartaban el mensaje. Cada tendero que pidió entrar se perdió
 * sin que nadie lo supiera.
 *
 * Estas pruebas no comprueban que "hoy funcione". Fijan las tres cosas que no
 * pueden volver a romperse sin que alguien lo note: que se guarde, que no se
 * guarde sin autorización de tratamiento de datos, y que la eliminación de
 * cuenta nazca con el plazo que la web promete por escrito.
 */

test('una solicitud de la web queda guardada con su radicado', function () {
    $respuesta = $this->postJson('/v1/solicitudes-free', [
        'nombre'           => 'Marcela Ruiz',
        'contacto'         => 'marcela@tiendalasflores.co',
        'barrio'           => 'Villa Carolina, Barranquilla',
        'tipo'             => 'comercio',
        'motivo'           => 'Tengo un comercio',
        'origen'           => 'landing:contacto',
        'pagina'           => '/comerciantes',
        'negocio'          => 'Tienda Las Flores',
        'tipo_negocio'     => 'Tienda de barrio',
        'consentimiento'   => true,
        'politica_version' => '2026-02',
    ]);

    $respuesta->assertCreated();

    $solicitud = LandingRequest::first();

    expect($solicitud->code)->toStartWith('SOL-')
        ->and($solicitud->type)->toBe('comercio')
        ->and($solicitud->name)->toBe('Marcela Ruiz')
        // Es un correo, así que además queda en su columna: el panel ofrece
        // "responder" sin tener que adivinar si eso era un WhatsApp.
        ->and($solicitud->contact_email)->toBe('marcela@tiendalasflores.co')
        // Lo que no tiene columna propia no se pierde.
        ->and($solicitud->payload['negocio'])->toBe('Tienda Las Flores')
        ->and($solicitud->payload['tipo_negocio'])->toBe('Tienda de barrio')
        // La prueba de la autorización: sin versión ni fecha es indemostrable.
        ->and($solicitud->policy_version)->toBe('2026-02')
        ->and($solicitud->accepted_at)->not->toBeNull();
});

test('un número de WhatsApp no se guarda como si fuera un correo', function () {
    $this->postJson('/v1/solicitudes-free', [
        'contacto'       => '300 246 4966',
        'tipo'           => 'vecino',
        'consentimiento' => true,
    ])->assertCreated();

    expect(LandingRequest::first()->contact)->toBe('300 246 4966')
        ->and(LandingRequest::first()->contact_email)->toBeNull();
});

test('sin autorización de tratamiento de datos no se guarda nada', function () {
    /*
     * La Ley 1581 de 2012 no admite consentimiento tácito. La web ya lo exige
     * en el navegador, pero un script que llame directo a la API no pasa por
     * ahí, y guardar un dato personal sin autorización es la infracción, no el
     * formulario mal llenado.
     */
    $this->postJson('/v1/solicitudes-free', [
        'nombre'         => 'Quien sea',
        'contacto'       => 'quien@sea.co',
        'consentimiento' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('consentimiento');

    expect(LandingRequest::count())->toBe(0);
});

test('lo que cae en la trampa responde que sí y no se guarda', function () {
    /*
     * A propósito 201 y no un error: un bot que recibe un error aprende a
     * esquivar la trampa la próxima vez; uno que recibe éxito, no.
     */
    $this->postJson('/v1/solicitudes-free', [
        'contacto'       => 'bot@ejemplo.co',
        'consentimiento' => true,
        'empresa'        => 'Relleno automático',
    ])->assertCreated();

    expect(LandingRequest::count())->toBe(0);
});

test('la eliminación de cuenta nace con el plazo que promete la web', function () {
    /*
     * /eliminar-cuenta dice quince días hábiles, y esa página es la que revisa
     * Google Play. El plazo tiene que existir en la fila desde el minuto uno:
     * si se calculara al mirarla, nadie podría saber si se incumplió.
     */
    $this->postJson('/v1/solicitudes-free', [
        'nombre'         => 'Kevin Salas',
        'contacto'       => 'kevin@ejemplo.co',
        'motivo'         => 'Eliminar mi cuenta',
        'consentimiento' => true,
    ])->assertCreated();

    $solicitud = LandingRequest::first();

    expect($solicitud->type)->toBe('eliminacion')
        ->and($solicitud->due_at)->not->toBeNull()
        ->and($solicitud->due_at->isAfter(now()))->toBeTrue();
});

test('el resto de solicitudes no lleva plazo inventado', function () {
    // Solo la eliminación tiene plazo comprometido. Ponerle uno a las demás
    // convertiría el indicador de vencidas en ruido.
    $this->postJson('/v1/solicitudes-free', [
        'contacto'       => 'vecino@ejemplo.co',
        'motivo'         => 'Soy vecino',
        'consentimiento' => true,
    ])->assertCreated();

    expect(LandingRequest::first()->due_at)->toBeNull();
});

test('sin tipo, el motivo del formulario alcanza para clasificarla', function () {
    // Si alguien añade un formulario en la web y olvida mandar el tipo, la
    // solicitud entra clasificada en vez de caer toda en "otro".
    $this->postJson('/v1/solicitudes-free', [
        'contacto'       => 'repartidor@ejemplo.co',
        'motivo'         => 'Quiero repartir',
        'consentimiento' => true,
    ])->assertCreated();

    expect(LandingRequest::first()->type)->toBe('domiciliario');
});

test('la hora de la autorización queda en hora colombiana', function () {
    /*
     * El navegador manda `toISOString()`, que es UTC. Sin convertirla, una
     * autorización firmada a las 9 de la noche en Barranquilla quedaba
     * guardada como las 2 de la mañana del día siguiente: cinco horas de
     * diferencia justo en el dato que existe para demostrar cuándo se
     * autorizó, que es lo que se mira si alguien reclama.
     */
    $this->postJson('/v1/solicitudes-free', [
        'contacto'    => 'vecino@ejemplo.co',
        'tipo'        => 'vecino',
        'consentimiento' => true,
        'aceptado_en' => '2026-08-22T02:37:00.000Z',
    ])->assertCreated();

    expect(LandingRequest::first()->accepted_at->format('Y-m-d H:i'))
        ->toBe('2026-08-21 21:37');
});

test('el radicado no se repite', function () {
    foreach (['uno@ejemplo.co', 'dos@ejemplo.co', 'tres@ejemplo.co'] as $correo) {
        $this->postJson('/v1/solicitudes-free', [
            'contacto'       => $correo,
            'tipo'           => 'vecino',
            'consentimiento' => true,
        ])->assertCreated();
    }

    expect(LandingRequest::pluck('code')->unique())->toHaveCount(3);
});

/* ==================== EL MÓDULO DEL PANEL ==================== */

/**
 * Quién alcanza la bandeja y qué puede hacer con ella.
 *
 * El reparto no es un detalle administrativo: una solicitud de eliminación de
 * cuenta lleva la dirección y el teléfono de quien la pide, y tiene plazo
 * legal. Que solo la vean las áreas que deben verla es parte de lo que se
 * prometió al recogerla.
 */

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function comoAreaSol(string $codigo): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
    $area = Area::where('code', $codigo)->firstOrFail();

    $u = User::factory()->create([
        'rol' => 4, 'area_id' => $area->id, 'access_level' => 'gestor',
    ]);
    Sanctum::actingAs($u);

    return $u;
}

function solicitudDePrueba(array $extra = []): LandingRequest
{
    return LandingRequest::create(array_merge([
        'code'     => LandingRequest::siguienteRadicado(),
        'type'     => 'comercio',
        'name'     => 'Marcela Ruiz',
        'contact'  => 'marcela@ejemplo.co',
        'state'    => LandingRequest::NUEVA,
    ], $extra));
}

test('Comercial ve la bandeja y la atiende', function () {
    solicitudDePrueba();
    comoAreaSol('comercial');

    $this->getJson('/v1/admin/solicitudes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('summary.nuevas', 1);
});

test('SST no alcanza las solicitudes de la web', function () {
    // No es suyo y lleva datos personales de terceros: la bandeja no es un
    // tablón que pueda mirar cualquiera con cuenta de panel.
    solicitudDePrueba();
    comoAreaSol('sst');

    $this->getJson('/v1/admin/solicitudes')->assertForbidden();
});

test('Gerencia mira pero no toca', function () {
    $s = solicitudDePrueba();
    comoAreaSol('gerencia');

    $this->getJson('/v1/admin/solicitudes')->assertOk();
    $this->putJson("/v1/admin/solicitudes/{$s->id}", ['state' => 1])
        ->assertForbidden();
});

test('descartar sin decir por qué no se puede', function () {
    /*
     * Descartar en silencio deja a la siguiente persona sin saber si ya se
     * habló con ese tendero o si nadie lo miró. En una eliminación de cuenta
     * es además la constancia de qué se hizo con la petición.
     */
    $s = solicitudDePrueba();
    comoAreaSol('comercial');

    $this->putJson("/v1/admin/solicitudes/{$s->id}", ['state' => 3])
        ->assertStatus(422);

    $this->putJson("/v1/admin/solicitudes/{$s->id}", [
        'state'      => 3,
        'resolution' => 'Es el mismo negocio que ya está afiliado.',
    ])->assertOk();

    expect($s->fresh()->state)->toBe(LandingRequest::DESCARTADA);
});

test('atender deja constancia de cuándo se atendió', function () {
    $s = solicitudDePrueba();
    comoAreaSol('comercial');

    $this->putJson("/v1/admin/solicitudes/{$s->id}", [
        'state'      => 2,
        'resolution' => 'Se llamó y quedó agendado para el jueves.',
    ])->assertOk();

    expect($s->fresh()->handled_at)->not->toBeNull();
});

test('una eliminación pasada de plazo se ve como vencida', function () {
    // Es lo que convierte el incumplimiento de una promesa pública en algo que
    // alguien puede ver antes de que reclamen.
    solicitudDePrueba([
        'type'   => 'eliminacion',
        'due_at' => now()->subDay(),
    ]);
    comoAreaSol('calidad');

    $this->getJson('/v1/admin/solicitudes')
        ->assertOk()
        ->assertJsonPath('data.0.overdue', true)
        ->assertJsonPath('summary.vencidas', 1);
});

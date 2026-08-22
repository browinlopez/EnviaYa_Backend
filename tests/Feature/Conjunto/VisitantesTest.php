<?php

use App\Models\Conjunto\ComplexStaff;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Control de acceso de quien NO es usuario de la plataforma, y la ficha del
 * conjunto.
 *
 * La portería dejó de ser sólo de domiciliarios de EnviaYa: por la puerta de un
 * conjunto pasan visitas, personal de servicio, contratistas y los domicilios
 * de otras plataformas. Nada de eso dejaba rastro, así que el edificio no tenía
 * su minuta y el resumen decía «entradas» cuando eran «entradas de
 * domiciliarios de EnviaYa».
 *
 * Los ayudantes `conjuntoConPersonal` y `repartidorConPedidoEn` viven en
 * `PorteriaTest.php`; Pest carga todos los archivos del directorio, así que
 * están disponibles aquí sin volver a escribirlos.
 */

test('un visitante se registra sin ser usuario de la plataforma', function () {
    $c = conjuntoConPersonal();

    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind'         => 'visitante',
        'visitor_name' => 'Marta Robles',
        'tower'        => '4',
        'apartment'    => '1203',
    ])->assertCreated();

    $fila = DB::table('complex_entries')->latest('id')->first();

    /*
     * Ni cuenta, ni domiciliario, ni verificación. Quien viene hoy a ver a su
     * hermana no tiene por qué ser usuario de la plataforma: lo que importa es
     * el hecho —entró, a esta hora, a este apartamento— y eso es la entrada.
     */
    expect($fila->kind)->toBe('visitante')
        ->and($fila->domiciliary_id)->toBeNull()
        ->and($fila->method)->toBe('manual')
        ->and($fila->visitor_name)->toBe('Marta Robles')
        ->and(DB::table('user')->where('name', 'Marta Robles')->exists())->toBeFalse();
});

test('sin nombre no se registra nada', function () {
    $c = conjuntoConPersonal();

    Sanctum::actingAs($c['user']);

    // El nombre es lo único imprescindible: una entrada sin nombre no sirve
    // como registro de nada.
    $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind' => 'visitante',
    ])->assertStatus(422);
});

test('si el conjunto lo exige, hace falta quien autorice', function () {
    $c = conjuntoConPersonal();

    DB::table('residential_complexes')
        ->where('complex_id', $c['complexId'])
        ->update(['require_authorization' => true]);

    Sanctum::actingAs($c['user']);

    // La regla es del edificio y se comprueba en el SERVIDOR: tiene que valer
    // aunque la petición venga de otro sitio que no sea el panel.
    $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind'         => 'visitante',
        'visitor_name' => 'Marta Robles',
    ])->assertStatus(422);

    $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind'          => 'visitante',
        'visitor_name'  => 'Marta Robles',
        'authorized_by' => 'Sra. Vecina',
    ])->assertCreated();
});

test('la salida se registra una vez y no se pisa', function () {
    $c = conjuntoConPersonal();

    Sanctum::actingAs($c['user']);

    $id = $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind' => 'servicio', 'visitor_name' => 'Tecnico de gas',
    ])->json('entry_id');

    $this->putJson("/v1/conjunto/porteria/entradas/{$id}/salida")->assertOk();

    /*
     * La primera salida es la que ocurrió. Una segunda pulsación —por duda o
     * por doble clic— reescribiría la hora y falsearía cuánto estuvo adentro.
     */
    $this->putJson("/v1/conjunto/porteria/entradas/{$id}/salida")
        ->assertStatus(409);
});

test('quien esta adentro son los de hoy sin salida', function () {
    $c = conjuntoConPersonal();

    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind' => 'visitante', 'visitor_name' => 'Sigue adentro',
    ])->assertCreated();

    $fuera = $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind' => 'visitante', 'visitor_name' => 'Ya salio',
    ])->json('entry_id');

    $this->putJson("/v1/conjunto/porteria/entradas/{$fuera}/salida")->assertOk();

    /*
     * Y una de anteayer sin salida: NO es alguien que lleva dos días adentro,
     * es una salida que nadie anotó. Arrastrarlas haría crecer el conteo para
     * siempre y la cifra dejaría de significar nada.
     */
    DB::table('complex_entries')->insert([
        'complex_id' => $c['complexId'], 'kind' => 'visitante',
        'method' => 'manual', 'visitor_name' => 'De anteayer',
        'orders_count' => 0, 'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    $r = $this->getJson('/v1/conjunto/porteria/adentro')->assertOk();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.nombre'))->toBe('Sigue adentro');
});

test('una entrada de otro conjunto no se puede cerrar', function () {
    $mio  = conjuntoConPersonal();
    $otro = conjuntoConPersonal();

    Sanctum::actingAs($otro['user']);
    $id = $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind' => 'visitante', 'visitor_name' => 'Del vecino',
    ])->json('entry_id');

    Sanctum::actingAs($mio['user']);

    // 404 y no 403: confirmar que existe pero es de otro conjunto ya sería
    // decir algo del edificio del vecino.
    $this->putJson("/v1/conjunto/porteria/entradas/{$id}/salida")
        ->assertStatus(404);
});

test('el resumen separa domiciliarios de visitas', function () {
    $c = conjuntoConPersonal();

    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind' => 'visitante', 'visitor_name' => 'Una visita',
    ])->assertCreated();

    $r = $this->getJson('/v1/conjunto/resumen')->assertOk();

    /*
     * Sumados escondería justo lo que importa: uno lo verifica el sistema y el
     * otro lo anota el celador, que son dos niveles de certeza distintos.
     */
    expect($r->json('hoy.externos'))->toBe(1)
        ->and($r->json('hoy.domiciliarios'))->toBe(0)
        ->and($r->json('hoy.adentro'))->toBe(1);
});

test('la proporcion de codigo no cuenta a los visitantes', function () {
    $c = conjuntoConPersonal();

    Sanctum::actingAs($c['user']);

    // Tres visitas, ningún domiciliario.
    foreach (['A', 'B', 'C'] as $n) {
        $this->postJson('/v1/conjunto/porteria/visitantes', [
            'kind' => 'visitante', 'visitor_name' => "Visita {$n}",
        ])->assertCreated();
    }

    $r = $this->getJson('/v1/conjunto/resumen')->assertOk();

    /*
     * Un visitante no tiene código que generar ni cédula que verificar contra
     * nada. Metidos en esta proporción la harían caer sin que nadie hubiera
     * dejado de pedir el código, y el administrador regañaría a su portería
     * por un cambio que no ocurrió.
     */
    expect($r->json('identificacion.total'))->toBe(0);
});

/* --------------------- PERFIL DEL CONJUNTO ---------------------------- */

test('el dueno corrige la ficha pero no las torres ni la ubicacion', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);

    Sanctum::actingAs($c['user']);

    $this->putJson('/v1/conjunto/perfil', [
        'name'       => 'Los Almendros Etapa II',
        'phone'      => '3001234567',
        'gate_notes' => 'Despues de las 10 de la noche no se reciben domicilios.',
        // Los dos que no se aceptan: de las torres sale la medida del tamaño
        // del conjunto, y de la ubicación heredan sus coordenadas las
        // direcciones de quienes ya viven ahí.
        'towers_count' => 999,
        'latitude'     => 1.23,
    ])->assertOk();

    $fila = DB::table('residential_complexes')
        ->where('complex_id', $c['complexId'])->first();

    expect($fila->name)->toBe('Los Almendros Etapa II')
        ->and($fila->gate_notes)->toContain('10 de la noche')
        ->and((int) $fila->towers_count)->toBe(4);
});

test('un celador ve la ficha del conjunto pero no la cambia', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);

    Sanctum::actingAs($c['user']);

    // La ve —las notas de portería son instrucciones que tiene que tener a la
    // vista— pero cambiarlas es del administrador.
    $r = $this->getJson('/v1/conjunto/me')->assertOk();

    expect($r->json('permissions.perfil.view'))->toBeTrue()
        ->and($r->json('permissions.perfil.manage'))->toBeFalse();

    $this->putJson('/v1/conjunto/perfil', ['name' => 'No deberia'])
        ->assertStatus(403);
});

test('el detalle de residentes no lleva correo ni telefono', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    repartidorConPedidoEn($c['complexId']);

    Sanctum::actingAs($c['user']);

    $r = $this->getJson('/v1/conjunto/residentes/detalle')->assertOk();

    /*
     * Una administración lleva legítimamente el registro de quién vive en su
     * edificio. Los datos de CONTACTO son otra cosa: pertenecen a la relación
     * de cada vecino con la plataforma, y un listado con los teléfonos de dos
     * mil hogares deja de ser un registro de residentes para ser una base de
     * mercadeo.
     */
    $crudo = $r->getContent();

    expect($crudo)->not->toContain('email')
        ->and($crudo)->not->toContain('phone')
        ->and($r->json('meta.per_page'))->toBe(20);
});

test('el detalle de residentes es de otro conjunto para nadie', function () {
    $mio  = conjuntoConPersonal(ComplexStaff::DUENO);
    $otro = conjuntoConPersonal(ComplexStaff::DUENO);

    // El residente vive en el conjunto del vecino.
    repartidorConPedidoEn($otro['complexId']);

    Sanctum::actingAs($mio['user']);

    // No hay parámetro que cambiar: el conjunto sale de la sesión.
    $r = $this->getJson('/v1/conjunto/residentes/detalle')->assertOk();

    expect($r->json('meta.total'))->toBe(0);
});

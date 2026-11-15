<?php

use App\Models\Area;
use App\Models\Marketing\AdCampaign;
use App\Models\Marketing\PushCampaign;
use App\Models\Operacion\CashDeposit;
use App\Models\Operacion\LandingRequest;
use App\Models\Operacion\Pqrs;
use App\Models\Operacion\SafetyIncident;
use App\Models\Operacion\Settlement;
use App\Models\Rol;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Los filtros `?state=` de los listados del panel aceptan solo lo que existe.
 *
 * ESTO SALIÓ DE MEDIR QUÉ CONSTANTES NADIE HABÍA SABOTEADO, el 15 de
 * noviembre de 2026. Seis listados del panel metían `$request->query('state')`
 * en el `where` **sin lista blanca**: `?state=99` devolvía una lista vacía
 * como si fuera una respuesta válida. `AGENTS.md` lo prohíbe expresamente, en
 * la misma línea que `orderBy($input)` sin whitelist.
 *
 * Y era la misma causa que dejaba constantes muertas: quien necesitaba la
 * lista de estados escribió los números a mano en la validación —o no validó
 * nada— así que `AdCampaign::PAUSADA`, `CashDeposit::ESTADOS` y ocho más
 * existían sin que las nombrara nadie en `app/`. Cablear la lista blanca las
 * resucita y cierra el filtro con el mismo cambio.
 *
 * CON LITERALES Y NO CON LAS CONSTANTES, y esta es la parte que importa.
 * Generar la entrada desde `Pqrs::ESTADOS` cuando la validación también sale
 * de `Pqrs::ESTADOS` es una tautología: al cambiar la constante cambian los
 * dos lados y la prueba sigue verde. Es exactamente el patrón que el primer
 * barrido de sabotaje encontró con `A_CREDITO`. Así que acá los estados van
 * escritos: si alguien renumera un estado, esto falla, que es el punto.
 *
 * Los valores también están guardados en columnas de filas que ya existen
 * —`pqrs.state`, `settlements.state`, `cash_deposits.state`—, así que son
 * vocabulario de datos y no un detalle interno: renumerarlos deja las filas
 * viejas mal clasificadas.
 */
function comoSistemaParaFiltros(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    // `sistema` es la única área con `['*']`, así que alcanza los seis módulos
    // sin montar seis usuarios distintos.
    $area = Area::where('code', 'sistema')->firstOrFail();

    $u = User::factory()->create([
        'rol' => 4, 'area_id' => $area->id, 'access_level' => 'gestor',
    ]);
    Sanctum::actingAs($u);

    return $u;
}

/**
 * Los seis listados, con sus estados ESCRITOS A MANO.
 *
 * @return array<string, array{0: string, 1: array<int, int|string>, 2: int|string}>
 */
dataset('listados con filtro de estado', [
    'campañas de pauta' => ['/v1/admin/marketing/campaigns', [0, 1, 2, 3], 99],
    'incidentes de SST' => ['/v1/admin/sst/incidentes', [0, 1, 2], 99],
    'liquidaciones' => ['/v1/admin/settlements', [0, 1, 2, 3], 99],
    'PQRS' => ['/v1/admin/pqrs', [0, 1, 2, 3], 99],
    'solicitudes de la web' => ['/v1/admin/solicitudes', [0, 1, 2, 3], 99],
    'depósitos de efectivo' => ['/v1/admin/cash-deposits', ['pendiente', 'confirmada', 'rechazada'], 'inventado'],
]);

test('sin filtro el listado contesta', function (string $ruta, array $validos, $invalido) {
    /*
     * CONTROL, Y VA PRIMERO. Si el endpoint contestara 403 por un permiso mal
     * puesto, los 200 de abajo no llegarían nunca y «rechaza el inválido»
     * sería verdad sin significar nada: estaría rechazando todo.
     */
    comoSistemaParaFiltros();

    expect($validos)->not->toBeEmpty()
        ->and($invalido)->not->toBeIn($validos);

    $this->getJson($ruta)->assertSuccessful();
})->with('listados con filtro de estado');

test('cada estado que existe se acepta', function (string $ruta, array $validos, $invalido) {
    comoSistemaParaFiltros();

    foreach ($validos as $estado) {
        $this->getJson($ruta.'?state='.$estado)
            ->assertSuccessful();
    }
})->with('listados con filtro de estado');

test('un estado que no existe se rechaza, no devuelve vacio', function (string $ruta, array $validos, $invalido) {
    comoSistemaParaFiltros();

    /*
     * LO QUE ESTO FIJA: antes esto contestaba 200 con `data: []`. Una lista
     * vacía es una respuesta legítima —puede que de verdad no haya nada— así
     * que el panel no podía distinguir «no hay» de «preguntaste mal».
     */
    $this->getJson($ruta.'?state='.$invalido)
        ->assertStatus(422)
        ->assertJsonValidationErrors('state');
})->with('listados con filtro de estado');

/* --------------------------------------------------------------------- */

test('los estados valen lo que las filas guardadas dicen', function () {
    /*
     * Con los números escritos, no con las constantes. Es la red contra
     * renumerar: `settlements.state` tiene filas con estos valores dentro.
     */
    expect(AdCampaign::ESTADOS)->toBe([0, 1, 2, 3]);
    expect(SafetyIncident::ESTADOS)->toBe([0, 1, 2]);
    expect(Settlement::ESTADOS)->toBe([0, 1, 2, 3]);
    expect(Pqrs::ESTADOS)->toBe([0, 1, 2, 3]);
    expect(LandingRequest::ESTADOS)->toBe([0, 1, 2, 3]);
    expect(CashDeposit::ESTADOS)->toBe(['pendiente', 'confirmada', 'rechazada']);
});

test('ninguna lista de estados tiene valores repetidos', function () {
    /*
     * Lo que el resto del código necesita de un estado es que no colisione con
     * otro, y es justo lo que nadie comprobaba: cada sitio escribe y lee por la
     * misma constante, así que si dos valieran lo mismo las cuentas seguirían
     * cuadrando entre ellas mientras los dos estados se confunden.
     */
    $listas = [
        'AdCampaign' => AdCampaign::ESTADOS,
        'SafetyIncident' => SafetyIncident::ESTADOS,
        'Settlement' => Settlement::ESTADOS,
        'Pqrs' => Pqrs::ESTADOS,
        'LandingRequest' => LandingRequest::ESTADOS,
        'CashDeposit' => CashDeposit::ESTADOS,
    ];

    foreach ($listas as $clase => $estados) {
        expect(count(array_unique($estados)))->toBe(count($estados), $clase);
    }
});

test('una campana de avisos no se puede marcar enviada a mano', function () {
    /*
     * LA EXCEPCIÓN QUE SE ENCONTRÓ AL COMPARAR LAS LISTAS antes de cablearlas.
     * `CampanaPushRequest` validaba `in:0,1,3` y el 2 —`ENVIADA`— faltaba a
     * propósito: lo pone el proceso que manda los avisos cuando los ha
     * mandado. Si se pudiera escribir a mano, se daría por enviada una campaña
     * que nadie recibió.
     *
     * Por eso su constante NO es la lista completa, y esto lo fija: si alguien
     * «arregla» la aparente omisión metiendo ENVIADA, esto falla.
     */
    expect(PushCampaign::ESTADOS_A_MANO)->toBe([0, 1, 3])
        ->and(PushCampaign::ENVIADA)->toBe(2)
        ->and(PushCampaign::ESTADOS_A_MANO)
        ->not->toContain(PushCampaign::ENVIADA);
});

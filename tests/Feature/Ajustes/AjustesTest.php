<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use App\Services\Ajustes;
use Laravel\Sanctum\Sanctum;

/**
 * AJUSTES DE LA PLATAFORMA
 *
 * Las reglas de la operación vivían en `config/services.php`, o sea en el `.env`:
 * cambiar la comisión del domiciliario era editar un archivo en el servidor y
 * reiniciar, y nadie sabía quién la había cambiado. La pantalla de Ajustes las
 * mostraba en solo lectura porque no había otra opción.
 *
 * Lo que se comprueba es lo que hace que un ajuste editable no sea peligroso:
 * que sin fila valga el valor por defecto, que lo que se guarda se lea igual,
 * que un valor imposible se rechace, que quede quién lo cambió y que el
 * mantenimiento no pueda dejar al panel sin forma de apagarlo.
 */

function comoAreaAjustes(string $codigo, string $nivel = Area::NIVEL_GESTOR): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', $codigo)->firstOrFail()->id,
        'access_level' => $nivel,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

beforeEach(fn () => Ajustes::olvidar());

it('sin fila en la tabla vale el valor por defecto', function () {
    /*
     * Es lo que permite que una instalación nueva funcione sin sembrar nada, y
     * que el `.env` siga siendo la línea base. Si hubiera que sembrar los
     * ajustes, una base recién creada repartiría el domicilio al 0 %.
     */
    expect(Ajustes::valor('operacion.reparto_domiciliario'))
        ->toBe((float) config('services.domiciliary_share', 0.25));

    expect(Ajustes::valor('operacion.entregas_simultaneas'))
        ->toBe((int) config('services.max_active_deliveries', 3));

    expect(Ajustes::valor('app.mantenimiento'))->toBeFalse();
});

it('lo que se guarda se lee con su tipo, no como texto', function () {
    comoAreaAjustes('sistema');

    /*
     * El cuerpo va ANIDADO por grupo. Con las claves punteadas planas, Laravel
     * las lee como rutas dentro de un arreglo y las reglas no encuentran nada:
     * la petición respondía 200 sin guardar ni rechazar. Las claves del catálogo
     * ya son `grupo.clave`, así que anidar es la forma natural.
     */
    $this->putJson('/v1/admin/settings', [
        'operacion' => [
            'entregas_simultaneas' => 5,
            'reparto_domiciliario' => 0.3,
        ],
        'app' => ['mantenimiento' => true],
    ])->assertOk();

    Ajustes::olvidar();

    /*
     * Todo se guarda en una columna de texto, así que sin la conversión del
     * catálogo `app.mantenimiento` volvería como la cadena "1" —o peor, "0", que
     * en PHP es falsa pero en JavaScript es verdadera—. Un modo mantenimiento
     * que se enciende solo por el tipo de dato es el fallo que nadie encuentra
     * leyendo el código.
     */
    expect(Ajustes::valor('operacion.entregas_simultaneas'))->toBe(5);
    expect(Ajustes::valor('operacion.reparto_domiciliario'))->toBe(0.3);
    expect(Ajustes::valor('app.mantenimiento'))->toBeTrue();
});

it('rechaza un reparto imposible', function () {
    comoAreaAjustes('sistema');

    // Más del 100 % del domicilio para el domiciliario significa que la
    // plataforma paga por cada entrega.
    $this->putJson('/v1/admin/settings', ['operacion' => ['reparto_domiciliario' => 1.4]])
        ->assertStatus(422);

    $this->putJson('/v1/admin/settings', ['operacion' => ['entregas_simultaneas' => 0]])
        ->assertStatus(422);

    expect(Ajustes::valor('operacion.entregas_simultaneas'))->toBe(3);
});

it('una clave inventada no se guarda', function () {
    comoAreaAjustes('sistema');

    $this->putJson('/v1/admin/settings', ['operacion' => ['lo_que_sea' => 99]])
        ->assertOk()
        ->assertJsonPath('message', 'No había nada que cambiar.');

    expect(\Illuminate\Support\Facades\DB::table('platform_settings')->count())->toBe(0);
});

it('queda quién lo cambió', function () {
    $yo = comoAreaAjustes('sistema');

    $this->putJson('/v1/admin/settings', ['operacion' => ['horas_estancado' => 12]])->assertOk();

    // Cambiar el reparto mueve plata en cada pedido que venga; sin autor, seis
    // meses después nadie puede explicar por qué marzo no cuadra con abril.
    $fila = \Illuminate\Support\Facades\DB::table('platform_settings')
        ->where('key', 'operacion.horas_estancado')
        ->first();

    expect((int) $fila->updated_by)->toBe((int) $yo->user_id);

    $r = $this->getJson('/v1/admin/settings')->assertOk();
    expect($r->json('audit.0.key'))->toBe('operacion.horas_estancado');
});

it('solo guarda lo que de verdad cambió', function () {
    comoAreaAjustes('sistema');

    // Mandar el valor que ya tenía no puede ensuciar la auditoría con un cambio
    // que no ocurrió.
    $this->putJson('/v1/admin/settings', [
        'operacion' => ['entregas_simultaneas' => (int) config('services.max_active_deliveries', 3)],
    ])->assertOk()->assertJsonPath('changes', []);

    expect(\Illuminate\Support\Facades\DB::table('platform_settings')->count())->toBe(0);
});

it('se puede volver al valor de fábrica', function () {
    comoAreaAjustes('sistema');

    $this->putJson('/v1/admin/settings', ['operacion' => ['entregas_simultaneas' => 9]])->assertOk();
    Ajustes::olvidar();
    expect(Ajustes::valor('operacion.entregas_simultaneas'))->toBe(9);

    $this->putJson('/v1/admin/settings/restablecer', [
        'key' => 'operacion.entregas_simultaneas',
    ])->assertOk();

    Ajustes::olvidar();
    expect(Ajustes::valor('operacion.entregas_simultaneas'))->toBe(3);
});

it('quien solo consulta los ve y no los cambia', function () {
    comoAreaAjustes('sistema', Area::NIVEL_CONSULTA);

    $this->getJson('/v1/admin/settings')->assertOk();

    $this->putJson('/v1/admin/settings', ['app' => ['mantenimiento' => true]])
        ->assertForbidden();

    expect(Ajustes::valor('app.mantenimiento'))->toBeFalse();
});

it('un área ajena no los alcanza', function () {
    // `ajustes` es de Tecnología: parar la app no es una decisión de marketing.
    comoAreaAjustes('marketing');

    $this->getJson('/v1/admin/settings')->assertForbidden();
});

it('la pantalla recibe el catálogo, no solo los valores', function () {
    comoAreaAjustes('sistema');

    $r = $this->getJson('/v1/admin/settings')->assertOk();

    // La pantalla no sabe qué ajustes existen: se lo dice el servidor. Así
    // agregar uno es una entrada en el catálogo y nada más.
    expect($r->json('groups.0.key'))->toBe('operacion');
    expect($r->json('groups.0.settings.0.label'))->not->toBeEmpty();
    expect($r->json('groups.0.settings.0.help'))->not->toBeEmpty();
    expect($r->json('groups.0.settings.0.type'))->toBe('porcentaje');
});

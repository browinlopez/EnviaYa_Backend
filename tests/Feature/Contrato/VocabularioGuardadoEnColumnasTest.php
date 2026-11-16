<?php

use App\Http\Controllers\Auth\AccesoBiometricoController;
use App\Http\Controllers\Negocio\DocumentosDelDomiciliarioController;
use App\Models\Area;
use App\Models\Marketing\Coupon;
use App\Models\Operacion\CashDeposit;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

/**
 * Vocabulario que vive en columnas, y que nadie vigilaba.
 *
 * LAS SEIS DE ACÁ SALIERON DE LA TERCERA PASADA DEL BARRIDO, el 15 de
 * noviembre de 2026. Esa pasada midió 28 de 46 antes de que el sistema la
 * matara por memoria, y de esas 28 estas seis pasaron: ninguna prueba las
 * cazaba.
 *
 * TODAS SE AFIRMAN CON LITERALES, y no es una preferencia de estilo. Estos
 * valores están **guardados en columnas de filas que ya existen**
 * —`personal_access_tokens.name`, `cash_deposits.canal`, `coupons.type`,
 * `domiciliary_documents.type`— así que son vocabulario de datos y no un
 * detalle interno: renumerarlos deja las filas viejas sin clasificar. Y
 * afirmarlos contra la propia constante sería la tautología que el primer
 * barrido encontró con `A_CREDITO`.
 */
function comoSistemaDeVocabulario(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
    $area = Area::where('code', 'sistema')->firstOrFail();

    $u = User::factory()->create([
        'rol' => 4, 'area_id' => $area->id, 'access_level' => 'gestor',
    ]);
    Sanctum::actingAs($u);

    return $u;
}

/* ================= EL ACCESO CON HUELLA ================= */

/**
 * LA MÁS GRAVE DE LAS SEIS.
 *
 * `borrarLosBiometricos()` hace `tokens()->where('name', NOMBRE_TOKEN)
 * ->delete()`. Si ese nombre cambia, «desactivar el acceso con huella»
 * contesta **éxito** y el token sigue sirviendo: quien apagó la opción porque
 * perdió el teléfono cree que lo cerró y no lo cerró.
 *
 * Y HAY QUE MONTARLO CON EL LITERAL, no con la constante. El flujo normal
 * crea el token con `createToken(NOMBRE_TOKEN)` y lo borra con
 * `where('name', NOMBRE_TOKEN)`: los dos lados salen de la misma constante,
 * así que al cambiarla cambian los dos y el flujo sigue cuadrando consigo
 * mismo. Lo que NO cuadra es con los tokens ya emitidos, que es justo lo que
 * rompe un despliegue. Así que acá el token nace con el nombre escrito, como
 * están los de la base.
 */
test('desactivar la huella borra el token que ya estaba emitido', function () {
    // `user.rol` es clave foránea: sin la fila el insert falla.
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $u = User::factory()->create(['rol' => 1]);

    // Como lo tiene hoy la base: el nombre escrito, no la constante.
    $u->createToken('biometrico', ['biometrico']);

    expect(DB::table('personal_access_tokens')
        ->where('tokenable_id', $u->user_id)
        ->where('name', 'biometrico')
        ->count())->toBe(1);

    Sanctum::actingAs($u);
    deleteJson('/v1/biometrico')->assertSuccessful();

    /*
     * LO QUE ESTO FIJA: con el nombre cambiado, esta cuenta seguiría en 1 y la
     * respuesta seguiría diciendo «Acceso con huella desactivado».
     */
    expect(DB::table('personal_access_tokens')
        ->where('tokenable_id', $u->user_id)
        ->where('name', 'biometrico')
        ->count())->toBe(0);
});

test('la habilidad y el nombre del token biometrico valen lo que la base guarda', function () {
    /*
     * CON UNA SALVEDAD DICHA: hoy **nadie llama a `tokenCan()`** —se buscó en
     * todo `app/` y no aparece— así que cambiar `HABILIDAD` no cambia ninguna
     * decisión ahora mismo. Lo que fija esto es el vocabulario guardado en
     * `personal_access_tokens.abilities`, para el día que alguien escriba esa
     * comprobación y espere encontrar `biometrico`.
     *
     * Decirlo importa: afirmar que esta constante «concede un permiso» sería
     * describir código que no existe.
     */
    $clase = AccesoBiometricoController::class;

    expect($clase::NOMBRE_TOKEN)->toBe('biometrico')
        ->and($clase::HABILIDAD)->toBe('biometrico');
});

/* ================= EL CANAL DE UN DEPÓSITO ================= */

test('el canal de un deposito no se confunde con su estado', function () {
    /*
     * La mutación que pasó ponía `CONSIGNACION` valiendo lo mismo que
     * `PENDIENTE`. Son dos vocabularios distintos en dos columnas distintas
     * —`canal` y `state`— y nada comprobaba que no colisionaran.
     *
     * EL DAÑO: `EfectivoDeLaTienda` filtra `canal = CONSIGNACION` para lo que
     * entró por plataforma. Con el valor pisado, esa consulta busca
     * `'pendiente'` en la columna del canal y **el panel del tendero deja de
     * ver los depósitos de plataforma**.
     */
    expect(CashDeposit::CONSIGNACION)->toBe('consignacion');
    expect(CashDeposit::MOSTRADOR)->toBe('mostrador');

    $canales = [CashDeposit::CONSIGNACION, CashDeposit::MOSTRADOR];
    $estados = CashDeposit::ESTADOS;

    expect(count(array_unique($canales)))->toBe(2)
        ->and(array_intersect($canales, $estados))->toBe([]);
});

/* ================= LOS TIPOS DE CUPÓN ================= */

test('los tres tipos de cupon son los que el panel manda', function () {
    /*
     * El tipo llega del cliente como cadena y `CuponRequest` lo valida contra
     * `Coupon::TIPOS`. Si la lista pierde un miembro, ese cupón **deja de
     * poderse crear**: 422. Traza hecha antes de escribir esto, porque la
     * primera lectura fue otra —pensé que un cupón fijo se cobraría como
     * porcentaje— y no es así: `descuentoPara` compara contra el valor
     * guardado, así que las filas viejas siguen bien. Lo que se rompe es
     * crear.
     */
    expect(Coupon::TIPO_PORCENTAJE)->toBe('percent');
    expect(Coupon::TIPO_FIJO)->toBe('fixed');
    expect(Coupon::TIPO_ENVIO_GRATIS)->toBe('free_shipping');

    expect(Coupon::TIPOS)->toBe(['percent', 'fixed', 'free_shipping']);
    expect(count(array_unique(Coupon::TIPOS)))->toBe(3);
});

test('se puede crear un cupon de cada tipo', function (string $tipo, float $valor) {
    comoSistemaDeVocabulario();

    /*
     * CON EL TIPO ESCRITO. Es lo que manda el panel por la red, así que es la
     * prueba de que la lista blanca sigue aceptándolo. Con `Coupon::TIPO_FIJO`
     * en la mano los dos lados saldrían de la constante y no probaría nada.
     */
    postJson('/v1/admin/marketing/coupons', [
        'code' => 'PRUEBA-'.strtoupper(substr($tipo, 0, 4)),
        'type' => $tipo,
        'value' => $valor,
        'starts_at' => now()->toDateString(),
        'ends_at' => now()->addMonth()->toDateString(),
    ])->assertSuccessful();
})->with([
    'porcentaje' => ['percent', 10.0],
    'monto fijo' => ['fixed', 5000.0],
    'envio gratis' => ['free_shipping', 0.0],
]);

test('un tipo de cupon inventado se rechaza', function () {
    comoSistemaDeVocabulario();

    /*
     * CONTROL de la prueba de arriba: si el endpoint contestara 422 por
     * cualquier cosa —un campo que falta, un permiso— los tres «se acepta» no
     * significarían nada. Acá se comprueba que lo que rechaza es el tipo.
     */
    postJson('/v1/admin/marketing/coupons', [
        'code' => 'PRUEBA-RARO',
        'type' => 'dos_por_uno',
        'value' => 10.0,
        'starts_at' => now()->toDateString(),
        'ends_at' => now()->addMonth()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors('type');
});

/* ================= LOS PAPELES DEL DOMICILIARIO ================= */

test('los seis papeles del domiciliario son los que se piden', function () {
    /*
     * `DocumentosDelDomiciliarioController` valida
     * `'type' => 'in:'.implode(',', self::TIPOS)`, así que un tipo que salga
     * de la lista **no se puede subir**: el domiciliario no puede entregar su
     * EPS y en el panel queda como si no la tuviera.
     *
     * Los valores están en `domiciliary_documents.type` de filas que ya
     * existen, de ahí los literales.
     */
    $clase = DocumentosDelDomiciliarioController::class;

    expect($clase::TIPOS)->toBe(['cedula', 'licencia', 'soat', 'tecnomecanica', 'arl', 'eps']);
    expect(count(array_unique($clase::TIPOS)))->toBe(6);

    /*
     * Iba a comprobar también que los tipos con vencimiento fueran un
     * subconjunto de estos, y no se puede: `CON_VENCIMIENTO` es **privada**.
     * Y está bien que lo sea —nadie de fuera la necesita— así que la regla
     * queda sin red desde acá y se dice en vez de callarse. Si algún día sale
     * de la clase, esta es la comprobación que falta.
     */
});

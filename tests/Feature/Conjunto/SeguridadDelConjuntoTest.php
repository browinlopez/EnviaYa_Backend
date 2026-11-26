<?php

use App\Exports\Conjunto\ReporteDelConjuntoExport;
use App\Models\Conjunto\ComplexStaff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Excel as FormatoExcel;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * La revisión de seguridad del panel de aliados (dueño y celador).
 *
 * Lo que se fija acá son los huecos que la revisión encontró ABIERTOS: una
 * llave que seguía valiendo después de que se le quitara el acceso a quien la
 * tenía, un Excel que ejecutaba lo que un vecino escribiera en su dirección, y
 * fechas mal escritas que tumbaban el servidor con un 500.
 */

/* ------------------- LLAVES QUE DEBEN DEJAR DE VALER ------------------- */

test('un conjunto desactivado deja afuera a su personal en la siguiente peticion', function () {
    /*
     * Desactivar el conjunto desde el panel interno no tocaba `complex_staff`,
     * y la puerta solo miraba la ficha: dueño y celadores seguían entrando al
     * panel, registrando entradas y bajando reportes de un edificio que la
     * plataforma ya había dado de baja.
     */
    $c = conjuntoConPersonal(ComplexStaff::DUENO);

    Sanctum::actingAs($c['user']);
    $this->getJson('/v1/conjunto/me')->assertOk();

    DB::table('residential_complexes')->where('complex_id', $c['complexId'])->update(['state' => 0]);

    $this->getJson('/v1/conjunto/me')->assertStatus(403);
    $this->getJson('/v1/conjunto/reportes')->assertStatus(403);
    $this->postJson('/v1/conjunto/porteria/visitantes', [
        'kind' => 'visitante', 'visitor_name' => 'Ana',
    ])->assertStatus(403);

    expect(DB::table('complex_entries')->count())->toBe(0);
});

test('una cuenta deshabilitada no sigue usando el panel con su token', function () {
    /*
     * `user.state` solo se miraba al iniciar sesión. Quien ya tenía el token
     * seguía entrando hasta que caducara —y los de Sanctum no caducan solos—.
     */
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);

    Sanctum::actingAs($c['user']);
    $this->getJson('/v1/conjunto/porteria/entradas')->assertOk();

    $c['user']->forceFill(['state' => 0])->save();

    $this->getJson('/v1/conjunto/porteria/entradas')->assertStatus(403);
});

test('un celador desactivado pierde el acceso en el acto', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);

    Sanctum::actingAs($c['user']);
    $this->getJson('/v1/conjunto/porteria/entradas')->assertOk();

    ComplexStaff::where('user_id', $c['user']->user_id)->update(['state' => false]);

    $this->getJson('/v1/conjunto/porteria/entradas')->assertStatus(403);
    $this->postJson('/v1/conjunto/porteria/verificar', ['document' => '123'])->assertStatus(403);
});

test('desactivar un celador le cierra todas las sesiones abiertas', function () {
    /*
     * La ficha desactivada ya cerraba ESTE panel, pero el token seguía vivo
     * para el resto del API autenticado. Desactivar a alguien de la portería
     * tiene que sacarlo de todas partes, no solo de una pantalla.
     */
    $dueno = conjuntoConPersonal(ComplexStaff::DUENO);

    $celador = User::factory()->create(['rol' => ComplexStaff::ROL_CELADOR]);
    $ficha = ComplexStaff::create([
        'user_id' => $celador->user_id,
        'complex_id' => $dueno['complexId'],
        'role' => ComplexStaff::CELADOR,
        'state' => true,
    ]);

    $celador->createToken('panel');
    $celador->createToken('celular');
    expect($celador->tokens()->count())->toBe(2);

    Sanctum::actingAs($dueno['user']);

    $this->putJson("/v1/conjunto/celadores/{$ficha->id}", ['state' => false])->assertOk();

    expect($celador->tokens()->count())->toBe(0)
        ->and($ficha->fresh()->state)->toBeFalse();
});

test('corregir el nombre de un celador queda en la auditoria del usuario', function () {
    /*
     * Se escribía con `User::where()->update()`, que salta los eventos del
     * modelo: el cambio no dejaba rastro de quién lo hizo ni del valor
     * anterior, a diferencia de cualquier otra edición de usuario.
     */
    config(['audit.console' => true]);

    $dueno = conjuntoConPersonal(ComplexStaff::DUENO);

    $celador = User::factory()->create(['rol' => ComplexStaff::ROL_CELADOR, 'name' => 'Pedro']);
    $ficha = ComplexStaff::create([
        'user_id' => $celador->user_id,
        'complex_id' => $dueno['complexId'],
        'role' => ComplexStaff::CELADOR,
        'state' => true,
    ]);

    Sanctum::actingAs($dueno['user']);

    $this->putJson("/v1/conjunto/celadores/{$ficha->id}", ['name' => 'Pedro Pablo'])->assertOk();

    $huella = DB::table('audits')
        ->where('auditable_type', User::class)
        ->where('auditable_id', $celador->user_id)
        ->where('event', 'updated')
        ->first();

    expect($celador->fresh()->name)->toBe('Pedro Pablo')
        ->and($huella)->not->toBeNull()
        ->and($huella->old_values)->toContain('Pedro');
});

/* ----------------------- EL EXCEL DEL ADMINISTRADOR ----------------------- */

test('el excel del reporte no ejecuta formulas escritas por terceros', function () {
    /*
     * La torre la escribe el COMPRADOR al registrar su dirección, y el nombre
     * del domiciliario lo escribe él. Con el binder por defecto, un texto que
     * empieza por «=» se guarda como FÓRMULA: el administrador abría el
     * reporte y Excel ejecutaba lo que un vecino hubiera escrito.
     *
     * Los datos tienen la forma de `PulsoDelConjunto::reporte()`: el Excel lee
     * `libro`, lo mismo que la pantalla.
     */
    $datos = [
        'periodo' => ['desde' => '2026-11-01', 'hasta' => '2026-11-26', 'dias' => 26],
        'libro' => [
            'entradas' => 2, 'entradas_anterior' => 0, 'pedidos_recibidos' => 3, 'visitantes' => 1,
            'identificacion' => ['codigo' => 1, 'cedula' => 0, 'manual' => 1],
            'por_dia' => [['dia' => '2026-11-25', 'etiqueta' => '25 nov', 'entradas' => 2]],
            'por_torre' => collect([['torre' => '=HYPERLINK("http://malo.test","Torre 1")', 'pedidos' => 3]]),
            'domiciliarios' => collect([['nombre' => '=1+1', 'entradas' => 1, 'por_qr' => 1.0, 'minutos' => 4]]),
        ],
    ];

    $binario = Excel::raw(new ReporteDelConjuntoExport($datos, '@Conjunto'), FormatoExcel::XLSX);

    $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($ruta, $binario);
    $libro = IOFactory::load($ruta);
    @unlink($ruta);

    // La primera fila de cada tabla de detalle es la 7 (ver `HojaDelReporte`).
    $torre = $libro->getSheetByName('Por torre')->getCell('A7');
    $domi = $libro->getSheetByName('Domiciliarios')->getCell('B7');

    expect($torre->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($torre->getValue())->toBe('=HYPERLINK("http://malo.test","Torre 1")')
        ->and($domi->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($domi->getValue())->toBe('=1+1')
        // Las cifras siguen siendo cifras: el administrador suma columnas.
        ->and($libro->getSheetByName('Por torre')->getCell('B7')->getDataType())->toBe(DataType::TYPE_NUMERIC);

    /*
     * Y en NINGUNA hoja hay una fórmula: el Consolidado repite torres y
     * domiciliarios en sus tablas cortas, y una hoja nueva que no herede el
     * binder volvería a abrir el hueco sin que la prueba de arriba lo note.
     */
    foreach ($libro->getAllSheets() as $hoja) {
        foreach ($hoja->getCellCollection()->getCoordinates() as $coordenada) {
            expect($hoja->getCell($coordenada)->getDataType())
                ->not->toBe(DataType::TYPE_FORMULA, $hoja->getTitle().'!'.$coordenada);
        }
    }
});

/* -------------------------- FECHAS MAL ESCRITAS -------------------------- */

test('una fecha que no es fecha en los reportes responde 422 y no 500', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);

    Sanctum::actingAs($c['user']);

    $this->getJson('/v1/conjunto/reportes?desde=basura')
        ->assertStatus(422)
        ->assertJsonValidationErrors('desde');

    $this->getJson('/v1/conjunto/reportes/excel?hasta=no-es-fecha')
        ->assertStatus(422)
        ->assertJsonValidationErrors('hasta');

    $this->getJson('/v1/conjunto/reportes?desde=2026-11-01&hasta=2026-11-20')->assertOk();
});

test('una fecha que no es fecha en el libro de entradas responde 422 y no 500', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);

    Sanctum::actingAs($c['user']);

    $this->getJson('/v1/conjunto/porteria/entradas?desde=basura')
        ->assertStatus(422)
        ->assertJsonValidationErrors('desde');

    $this->getJson('/v1/conjunto/porteria/entradas?desde=2026-11-01&hasta=2026-11-20')->assertOk();
});

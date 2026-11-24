<?php

use App\Models\Product\CatalogUpload;
use App\Models\Product\PharmacyProduct;
use App\Models\Product\Product;
use App\Models\Rol;
use App\Models\User;
use App\Services\PlantillaDeCatalogo;
use App\Support\ProductTypeSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * ==========================================================================
 * UNA DROGUERÍA CARGA SU INVENTARIO.
 *
 * La carga masiva era el único camino del backend que ignoraba el tipo de
 * negocio: leía seis campos fijos y nunca escribía `pharmacy_products`, que
 * llevaba la vida entera con cero filas. Una droguería podía subir su archivo
 * y los cuatro contadores decían que todo había entrado, pero el registro
 * INVIMA, el contenido y la fórmula médica se perdían en silencio —el peor
 * modo de fallar, porque nadie va a revisar quinientas filas para descubrirlo—.
 *
 * Lo que fija este archivo:
 *
 *   · que la plantilla de una droguería traiga SUS columnas;
 *   · que lo que se escribe en ellas acabe en `pharmacy_products`;
 *   · que la columna de la fórmula entienda cómo la escribe la gente;
 *   · que una celda rara NO tumbe la fila: el producto se vende igual;
 *   · y la pasada de CONTROL, que es la que de verdad importa: que la
 *     plantilla y la carga de una TIENDA sigan exactamente como estaban.
 * ========================================================================== */

/**
 * Un dueño con una droguería, su categoría y el tipo con el id que el esquema
 * espera.
 *
 * El id del tipo se fuerza a `TYPE_PHARMACY`. `tenderoConCatalogo()` usa
 * `insertGetId` y se queda con el que salga, que para una tienda da igual; acá
 * no: `ProductTypeSchema` busca el tipo POR SU NÚMERO, así que una droguería
 * con el tipo 7 no tendría campos de farmacia y la prueba pasaría en verde sin
 * haber probado nada.
 */
function drogueriaConCatalogo(): array
{
    Rol::firstOrCreate(
        ['rol_id' => 2],
        ['name' => 'tendero', 'guard_name' => 'web'],
    );

    $user = User::factory()->create(['rol' => 2]);

    $ownerId = DB::table('owner')->insertGetId(
        ['user_id' => $user->user_id, 'state' => 1],
        'owner_id',
    );

    $tipo = ProductTypeSchema::TYPE_PHARMACY;

    DB::table('category_business')->insert(['id' => $tipo, 'name' => 'Farmacia']);

    $mio = DB::table('business')->insertGetId([
        'name' => 'Droguería Vida Sana',
        'qualification' => 0,
        'state' => 1,
        'type' => $tipo,
    ], 'busines_id');

    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId,
        'busines_id' => $mio,
        'state' => 1,
    ]);

    $categoria = DB::table('category')->insertGetId([
        'name' => 'Dolor y fiebre',
        'business_category_id' => $tipo,
    ], 'category_id');

    DB::table('category_category_business')->insert([
        'category_id' => $categoria,
        'business_category_id' => $tipo,
    ]);

    return compact('user', 'mio', 'categoria');
}

/**
 * Un CSV con los encabezados que se le pasen.
 *
 * Propio y no el de `CargaDeCatalogoTest`: Pest solo carga los archivos que
 * corre, asi que un ayudante de otro archivo de pruebas no existe cuando esta
 * se lanza sola --y lanzarla sola es justo lo que se hace mientras se
 * escribe--. Con nombre distinto para que convivan cuando corre la suite.
 */
function csvDeDrogueria(array $filas, array $encabezados): UploadedFile
{
    $lineas = [implode(',', $encabezados)];

    foreach ($filas as $f) {
        $lineas[] = implode(',', array_map(
            fn ($v) => '"'.str_replace('"', '""', (string) $v).'"',
            $f,
        ));
    }

    return UploadedFile::fake()->createWithContent('catalogo.csv', implode('
', $lineas));
}

/** Sube el archivo y devuelve el expediente ya procesado. */
function subirADrogueria(array $t, UploadedFile $archivo): CatalogUpload
{
    Sanctum::actingAs($t['user']);

    $r = test()->post('/v1/negocio/productos/cargas', ['archivo' => $archivo])
        ->assertStatus(202);

    return CatalogUpload::find($r->json('data.catalog_upload_id'))->fresh();
}

/** Las seis columnas comunes, para los casos que no mandan las de farmacia. */
const COMUNES = ['codigo_barras', 'nombre', 'marca', 'categoria', 'precio', 'cantidad'];

/** El archivo de una droguería: las seis de siempre más las suyas. */
function archivoDeDrogueria(array $filas): UploadedFile
{
    return csvDeDrogueria($filas, [
        'codigo_barras', 'nombre', 'marca', 'categoria', 'precio', 'cantidad',
        'presentacion', 'contenido', 'registro_invima', 'requiere_formula',
        'principio_activo', 'dosis',
    ]);
}

/* ---------------------------------------------------------------------- */

test('la plantilla de una droguería trae las columnas de droguería', function () {
    $t = drogueriaConCatalogo();

    $hoja = (new PlantillaDeCatalogo)->generar($t['mio'])->getSheetByName('Productos');

    $cabecera = [];

    foreach (range('A', 'M') as $col) {
        $cabecera[] = $hoja->getCell("{$col}1")->getValue();
    }

    /*
     * Las seis primeras en su sitio y en su orden. Moverlas rompería todos los
     * archivos que los tenderos ya tienen llenos a medias en el escritorio.
     */
    expect(array_slice($cabecera, 0, 6))->toBe([
        'Código de barras', 'Precio', 'Cantidad', 'Nombre', 'Marca', 'Categoría',
    ]);

    /*
     * TRES COMUNES Y LUEGO LAS CUATRO DE DROGUERÍA.
     *
     * Contenido, presentación y frío los llena cualquier oficio, así que van
     * antes y los trae también el archivo de una tienda. Lo de después es lo
     * único que una droguería pregunta aparte.
     */
    expect(array_slice($cabecera, 6))->toBe([
        'Contenido', 'Presentación', 'Necesita frío',
        'Registro INVIMA', 'Requiere fórmula', 'Principio activo', 'Dosis',
    ]);
});

test('CONTROL: la tienda trae los comunes y NINGUNO de droguería', function () {
    /*
     * ÉSTA es la prueba de que el mecanismo sigue siendo POR TIPO.
     *
     * Una tienda trae las seis de siempre más los tres comunes --contenido,
     * presentación y frío, que los llena cualquier oficio-- y NINGUNA de
     * droguería: a un bulto de arroz no se le pide registro INVIMA.
     *
     * Antes esta prueba decía «seis columnas y ni una más», porque entonces
     * sólo farmacia declaraba columnas. Ya no: los comunes son de los cuatro.
     */
    $t = tenderoConCatalogo();

    $hoja = (new PlantillaDeCatalogo)->generar($t['mio'])->getSheetByName('Productos');

    /*
     * La ULTIMA columna, y preguntada ANTES de mirar ninguna celda.
     *
     * `getCell('J1')` no consulta: CREA la celda si no existe, y entonces la
     * hoja contesta que su última columna es la J. Preguntado en ese orden,
     * esto daba rojo con la plantilla correcta --y, peor, habría dado verde
     * con una plantilla de más columnas--.
     */
    expect($hoja->getHighestColumn())->toBe('I');

    $cabecera = [];

    foreach (range('A', 'I') as $col) {
        $cabecera[] = $hoja->getCell("{$col}1")->getValue();
    }

    expect(array_slice($cabecera, 6))->toBe(['Contenido', 'Presentación', 'Necesita frío']);
    expect($cabecera)->not->toContain('Registro INVIMA');
});

test('lo que una droguería escribe en sus columnas se guarda', function () {
    Storage::fake('local');

    $t = drogueriaConCatalogo();

    $carga = subirADrogueria($t, archivoDeDrogueria([
        ['7702057000011', 'ACETAMINOFÉN MK 500 MG', 'MK', 'Dolor y fiebre', '3800', '40',
            'Caja', '10 tabletas', 'INVIMA 2010M-014567', 'No', 'Acetaminofén', '500 mg'],
    ]));

    expect($carga->estado)->toBe('terminada');
    expect($carga->creados)->toBe(1);
    expect($carga->resumen['errores'] ?? [])->toBeEmpty();

    $producto = Product::where('barcode', '7702057000011')->first();

    expect($producto)->not->toBeNull();

    /*
     * EL CONTENIDO Y EL ENVASE, EN EL PRODUCTO. Son comunes a los cuatro
     * oficios y viven en `products`; lo de droguería, en su tabla.
     */
    expect($producto->content)->toBe('10 tabletas');
    expect($producto->presentation)->toBe('Caja');

    $farmacia = PharmacyProduct::where('products_id', $producto->products_id)->first();

    expect($farmacia)->not->toBeNull();
    expect($farmacia->registro_invima)->toBe('INVIMA 2010M-014567');
    expect($farmacia->requiere_formula)->toBeFalse();
    expect($farmacia->active_ingredient)->toBe('Acetaminofén');
    expect($farmacia->dosage)->toBe('500 mg');
});

test('lo que pide fórmula queda marcado, se escriba como se escriba', function () {
    Storage::fake('local');

    /*
     * Las cuatro formas salen de archivos de verdad: quien exporta de su
     * sistema manda «1» o «TRUE», quien lo llena a mano escribe «Sí», «si» o
     * una «X». Las cuatro quieren decir lo mismo y las cuatro tienen que
     * marcar el medicamento.
     */
    $t = drogueriaConCatalogo();

    $carga = subirADrogueria($t, archivoDeDrogueria([
        ['7702057000021', 'AMOXICILINA 500 MG', 'Genfar', 'Dolor y fiebre', '9500', '20',
            'Caja', '12 cápsulas', '', 'Sí', 'Amoxicilina', '500 mg'],
        ['7702057000022', 'LOSARTÁN 50 MG', 'MK', 'Dolor y fiebre', '12000', '15',
            'Caja', '30 tabletas', '', 'si', 'Losartán', '50 mg'],
        ['7702057000023', 'METFORMINA 850 MG', 'MK', 'Dolor y fiebre', '8900', '18',
            'Caja', '30 tabletas', '', '1', 'Metformina', '850 mg'],
        ['7702057000024', 'ENALAPRIL 20 MG', 'MK', 'Dolor y fiebre', '7400', '12',
            'Caja', '30 tabletas', '', 'X', 'Enalapril', '20 mg'],
    ]));

    expect($carga->resumen['errores'] ?? [])->toBeEmpty();
    expect($carga->creados)->toBe(4);

    expect(PharmacyProduct::where('requiere_formula', true)->count())->toBe(4);
});

test('sin decir nada en la columna, NO requiere fórmula', function () {
    Storage::fake('local');

    $t = drogueriaConCatalogo();

    subirADrogueria($t, archivoDeDrogueria([
        ['7702057000031', 'ALCOHOL ANTISÉPTICO 700 ML', 'JGB', 'Dolor y fiebre', '6500', '30',
            'Frasco', '700 ml', '', '', '', ''],
    ]));

    $producto = Product::where('barcode', '7702057000031')->first();

    /* Lo que sí trajo la fila, que es común a cualquier producto. */
    expect($producto->presentation)->toBe('Frasco');
    expect($producto->content)->toBe('700 ml');

    /*
     * Y NI UNA FILA DE FARMACIA, que es lo que cambió al separar los comunes.
     *
     * El alcohol antiséptico no es un medicamento: no tiene registro, ni
     * principio activo, ni pide fórmula. Antes se le fabricaba una fila con
     * todo en nulo sólo porque traía presentación y contenido.
     *
     * Sin fila, la ficha no pinta nada de droguería, que es lo correcto. Y
     * para «requiere fórmula» se lee como «no», que es el defecto seguro: lo
     * que no se dice no restringe la venta.
     */
    expect(PharmacyProduct::where('products_id', $producto->products_id)->exists())
        ->toBeFalse();
});

test('una celda rara en la fórmula se queja, pero el producto SÍ se vende', function () {
    Storage::fake('local');

    $t = drogueriaConCatalogo();

    $carga = subirADrogueria($t, archivoDeDrogueria([
        ['7702057000041', 'IBUPROFENO MK 400 MG', 'MK', 'Dolor y fiebre', '5200', '25',
            'Caja', '10 tabletas', 'INVIMA 2011M-001122', 'consultar', 'Ibuprofeno', '400 mg'],
    ]));

    /*
     * LAS DOS COSAS A LA VEZ, Y ÉSTE ES EL PUNTO DE LA PRUEBA.
     *
     * «consultar» no es sí ni no, y adivinarlo como «no» marcaría como libre un
     * medicamento que quizá pide fórmula. Así que se queja. Pero rechazar la
     * fila entera dejaría el ibuprofeno FUERA del surtido —invisible para quien
     * compra— por una celda que no hace falta para venderlo.
     *
     * El precio y las existencias entran, el motivo queda escrito, y el resto
     * de lo que sí se entendió se guarda.
     */
    expect($carga->creados)->toBe(1);
    expect($carga->resumen['errores'])->toHaveCount(1);
    expect($carga->resumen['errores'][0]['motivo'])->toContain('escribe Sí o No');
    expect($carga->resumen['errores'][0]['fila'])->toBe(2);

    $producto = Product::where('barcode', '7702057000041')->first();

    expect($producto)->not->toBeNull();

    expect(DB::table('products_business')
        ->where('busines_id', $t['mio'])
        ->where('products_id', $producto->products_id)
        ->exists())->toBeTrue();

    $farmacia = PharmacyProduct::where('products_id', $producto->products_id)->first();

    expect($farmacia->registro_invima)->toBe('INVIMA 2011M-001122');
    expect($farmacia->requiere_formula)->toBeFalse(); // el defecto, no lo escrito
});

test('subir dos veces el archivo no duplica la ficha de farmacia', function () {
    Storage::fake('local');

    $t = drogueriaConCatalogo();

    $filas = [
        ['7702057000051', 'OMEPRAZOL 20 MG', 'Genfar', 'Dolor y fiebre', '7800', '22',
            'Caja', '14 cápsulas', 'INVIMA 2013M-004455', 'No', 'Omeprazol', '20 mg'],
    ];

    subirADrogueria($t, archivoDeDrogueria($filas));

    /* Con el precio cambiado, que es por lo que se vuelve a subir un archivo. */
    $filas[0][4] = '8300';

    $segunda = subirADrogueria($t, archivoDeDrogueria($filas));

    expect($segunda->actualizados)->toBe(1);
    expect($segunda->creados)->toBe(0);

    expect(PharmacyProduct::count())->toBe(1);

    $producto = Product::where('barcode', '7702057000051')->first();

    expect((float) DB::table('products_business')
        ->where('busines_id', $t['mio'])
        ->where('products_id', $producto->products_id)
        ->value('price'))->toBe(8300.0);
});

test('un archivo sin las columnas de farmacia carga igual', function () {
    Storage::fake('local');

    /*
     * Una droguería que ya tiene su inventario en otro sistema tiene que poder
     * subirlo HOY, con las seis columnas de siempre, y completar lo demás
     * después. Si las columnas nuevas fueran obligatorias, la función que se
     * añadió para ayudarla le impediría empezar.
     */
    $t = drogueriaConCatalogo();

    $carga = subirADrogueria($t, csvDeDrogueria([
        ['7702057000061', 'SUERO ORAL PEDIALYTE', 'Pedialyte', 'Dolor y fiebre', '9900', '14'],
    ], COMUNES));

    expect($carga->estado)->toBe('terminada');
    expect($carga->creados)->toBe(1);
    expect($carga->resumen['errores'] ?? [])->toBeEmpty();

    /* Sin fila de farmacia: no se inventa una con todo en nulo. */
    expect(PharmacyProduct::count())->toBe(0);
});

test('CONTROL: una tienda que manda esas columnas NO las guarda', function () {
    Storage::fake('local');

    /*
     * El mecanismo es por tipo de negocio, y hay que comprobar que lo es de
     * verdad. Una tienda de abarrotes que mande una columna «registro_invima»
     * —copiando la plantilla de un conocido— no escribe nada en farmacia: el
     * tipo 1 no declara esas columnas, así que para él no existen.
     */
    $t = tenderoConCatalogo();

    $carga = subirADrogueria($t, archivoDeDrogueria([
        ['7702011000011', 'ARROZ DIANA 500 G', 'Diana', 'Abarrotes', '4800', '10',
            'Bolsa', '500 g', 'INVIMA 2010M-000000', 'Sí', 'arroz', '500 g'],
    ]));

    expect($carga->creados)->toBe(1);
    expect($carga->resumen['errores'] ?? [])->toBeEmpty();

    expect(PharmacyProduct::count())->toBe(0);
});

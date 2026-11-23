<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product\ProductBusiness;
use App\Support\ProductTypeSchema;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * LA PLANTILLA DEL CATÁLOGO, COMO UN EXCEL DE VERDAD.
 *
 * Era un CSV de seis líneas con los encabezados pegados y una fila de ejemplo
 * que, si nadie la borraba, se importaba como un producto más. Ahora es un
 * .xlsx pensado para llenarse rápido:
 *
 *  · LO OBLIGATORIO PRIMERO. Código, precio y cantidad van en A, B y C: para
 *    lo que ya está en el catálogo no hace falta más. Nombre, marca y
 *    categoría van después y en otro color, porque solo sirven para lo nuevo.
 *  · CADA COLUMNA SE EXPLICA SOLA. Al pararse en una celda Excel muestra qué
 *    poner (el «mensaje de entrada» de la validación), y la hoja
 *    «Instrucciones» lo dice con un ejemplo por columna.
 *  · LA CATEGORÍA ES UNA LISTA. Un desplegable con las categorías de las
 *    tiendas como esta. Escribirla a mano era la primera causa de filas
 *    rechazadas: «Lacteos» no es «Lácteos y Huevos».
 *  · EL CÓDIGO ES TEXTO. Si no, Excel lo pasa a 7,70201E+12 y pierde los
 *    ceros de adelante: el producto ya no se encuentra.
 *  · OPCIONALMENTE, CON TU CATÁLOGO ADENTRO. Para cambiar precios o
 *    existencias de muchos productos de una vez: se descarga, se edita y se
 *    vuelve a subir. Subirlo actualiza, no duplica.
 *
 * Los encabezados se escriben como los lee el importador (ver
 * ImportadorDeCatalogo::leerFila): «Código de barras» llega como
 * `codigo_de_barras`, «Categoría» como `categoria`.
 */
class PlantillaDeCatalogo
{
    private const MORADO = '1B1464';

    private const LILA = 'EEE9FF';

    private const GRIS = 'F5F3FA';

    private const AMBAR = 'FFF3D6';

    private const ROJO_SUAVE = 'FFE5E0';

    /** Filas con listas y formato: alcanza para una carga grande. */
    private const FILAS_MINIMAS = 2000;

    /** [encabezado, ancho, obligatoria, mensaje al pararse en la celda] */
    private const COLUMNAS = [
        'A' => ['Código de barras', 22, true,
            'El número debajo de las barras del empaque (8 a 14 dígitos). Con este código encontramos el producto en el catálogo.'],
        'B' => ['Precio', 14, true,
            'Tu precio de venta, solo el número: 4500 (sin $ ni puntos). Si lo vendes por kilo, el precio del kilo.'],
        'C' => ['Cantidad', 12, true,
            'Cuántas unidades tienes ahora. Número entero; 0 si se te acabó.'],
        'D' => ['Nombre', 38, false,
            'SOLO si el producto no está en el catálogo. Escríbelo como sale en el empaque: «Arroz Diana 500 g».'],
        'E' => ['Marca', 18, false,
            'Opcional, y solo para productos nuevos.'],
        'F' => ['Categoría', 26, false,
            'SOLO para productos nuevos. Elígela de la lista: escrita a mano suele no coincidir y la fila se rechaza.'],
    ];

    public function generar(int $negocioId, bool $conProductos = false): Spreadsheet
    {
        $libro = new Spreadsheet;
        $libro->getProperties()
            ->setCreator("VeciPa'Ya")
            ->setTitle('Plantilla del catálogo');

        $negocio = DB::table('business')->where('busines_id', $negocioId)->first(['name', 'type']);
        $nombreTienda = $negocio->name ?? null;
        $categorias = $this->categorias($negocioId);
        $productos = $conProductos ? $this->productos($negocioId) : [];

        /*
         * LAS COLUMNAS SALEN DEL TIPO DE NEGOCIO.
         *
         * Las seis de siempre --código, precio, cantidad, nombre, marca y
         * categoría-- las necesita cualquier negocio. Lo que una drogueria
         * añade --presentación, contenido, registro INVIMA, si pide
         * fórmula-- sale de `ProductTypeSchema`, que ya es el único sitio
         * donde viven los campos por tipo.
         *
         * Un tipo que no declara columnas no añade ninguna: la plantilla de
         * una tienda sigue siendo exactamente la de antes, y eso es lo que
         * fijan sus pruebas.
         */
        $columnas = $this->columnas((int) ($negocio->type ?? 0));

        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Productos');
        $this->hojaDeProductos($hoja, $productos, count($categorias), $columnas);

        $this->hojaDeInstrucciones($libro->createSheet(), $nombreTienda, $categorias, $columnas);
        $this->hojaDeListas($libro->createSheet(), $categorias);

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Las seis comunes más las del tipo, cada una con su letra.
     *
     * @return array<string, array{0:string, 1:int, 2:bool, 3:string, 4:?string}>
     *                                                                            [titulo, ancho, obligatoria, ayuda, campo del esquema o null]
     */
    private function columnas(int $tipo): array
    {
        $columnas = [];

        foreach (self::COLUMNAS as $letra => [$titulo, $ancho, $obligatoria, $ayuda]) {
            $columnas[$letra] = [$titulo, $ancho, $obligatoria, $ayuda, null];
        }

        /* Desde la G. Las extra son todas OPCIONALES a propósito: una
           drogueria que ya tiene su inventario en otro sistema tiene que
           poder subirlo hoy y completar lo demás después. */
        $letra = 'G';

        foreach (ProductTypeSchema::columnasDeCarga($tipo) as $campo => $def) {
            $columnas[$letra] = [$def['titulo'], $def['ancho'], false, $def['ayuda'], $campo];
            $letra++;
        }

        return $columnas;
    }

    /** La última letra usada, para los rangos que antes eran fijos. */
    private function ultimaColumna(array $columnas): string
    {
        return (string) array_key_last($columnas);
    }

    private function hojaDeProductos(Worksheet $h, array $productos, int $cuantasCategorias, array $columnas): void
    {
        $ultima = max(self::FILAS_MINIMAS, count($productos) + 500) + 1;
        $finCol = $this->ultimaColumna($columnas);

        foreach ($columnas as $col => [$titulo, $ancho, $obligatoria, $ayuda]) {
            $h->setCellValue("{$col}1", $titulo);
            $h->getColumnDimension($col)->setWidth($ancho);

            $estilo = $h->getStyle("{$col}1");
            $estilo->getFont()->setBold(true)->setSize(11)
                ->getColor()->setRGB($obligatoria ? 'FFFFFF' : self::MORADO);
            $estilo->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($obligatoria ? self::MORADO : self::LILA);
            $estilo->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);

            // La explicación también como comentario del encabezado: se ve al
            // pasar el mouse aunque no se esté editando.
            $nota = $h->getComment("{$col}1");
            $nota->getText()->createText(($obligatoria ? 'OBLIGATORIA. ' : 'OPCIONAL. ').$ayuda);
            $nota->setWidth('260pt')->setHeight('90pt');
        }

        $h->getRowDimension(1)->setRowHeight(30);
        $h->freezePane('A2');
        /* Calculado y no 'A1:F1': con las columnas del tipo, el filtro y el
           borde se quedaban a mitad de la cabecera. */
        $h->setAutoFilter("A1:{$finCol}1");
        $h->getStyle("A1:{$finCol}1")->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::MORADO);

        // Formatos: el código como TEXTO, precio con miles, cantidad entera.
        $h->getStyle("A2:A{$ultima}")->getNumberFormat()->setFormatCode('@');
        $h->getStyle("B2:B{$ultima}")->getNumberFormat()->setFormatCode('"$" #,##0');
        $h->getStyle("C2:C{$ultima}")->getNumberFormat()->setFormatCode('0');
        $h->getStyle("D2:{$finCol}{$ultima}")->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB(self::GRIS);

        // Validaciones: un mensaje al pararse en la celda y un aviso si lo
        // escrito no sirve. Avisan sin bloquear, salvo la categoría.
        foreach ($columnas as $col => [$titulo, , $obligatoria, $ayuda, $campo]) {
            $v = new DataValidation;
            $v->setShowInputMessage(true)
                ->setPromptTitle($titulo.($obligatoria ? ' (obligatoria)' : ''))
                ->setPrompt(mb_substr($ayuda, 0, 250))
                ->setAllowBlank(true);

            if ($col === 'B') {
                $v->setType(DataValidation::TYPE_DECIMAL)->setOperator(DataValidation::OPERATOR_GREATERTHAN)
                    ->setFormula1('0')->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setErrorTitle('Precio no válido')->setError('Escribe solo el número, mayor que cero. Ej.: 4500');
            } elseif ($col === 'C') {
                $v->setType(DataValidation::TYPE_WHOLE)->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)
                    ->setFormula1('0')->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setErrorTitle('Cantidad no válida')->setError('Un número entero, 0 o más. Ej.: 12');
            } elseif ($col === 'F' && $cuantasCategorias > 0) {
                $fin = $cuantasCategorias + 1;
                $v->setType(DataValidation::TYPE_LIST)->setShowDropDown(true)
                    ->setFormula1("Listas!\$A\$2:\$A\${$fin}")
                    ->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setErrorTitle('Categoría no válida')->setError('Elige una categoría de la lista.');
            } elseif ($campo !== null && ($columnas[$col][0] ?? '') === 'Requiere fórmula') {
                /* Sí/No desde la hoja de listas, no escrito a mano: la
                   plantilla ya tiene dicho que teclear a mano «era la
                   primera causa de filas rechazadas». */
                $v->setType(DataValidation::TYPE_LIST)->setShowDropDown(true)
                    ->setFormula1('Listas!$B$2:$B$3')
                    ->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setErrorTitle('Escribe Sí o No')->setError('Elige Sí o No de la lista.');
            } else {
                $v->setType(DataValidation::TYPE_NONE);
            }

            // La llave de la colección se escribe tal cual como rango (sqref).
            $h->setDataValidation("{$col}2:{$col}{$ultima}", $v);
        }

        // Un precio vacío en una fila con código se marca en rojo suave: es la
        // fila que el importador va a rechazar por «falta el precio».
        $sinPrecio = new Conditional;
        $sinPrecio->setConditionType(Conditional::CONDITION_EXPRESSION)
            ->addCondition('AND($A2<>"",$B2="")');
        $sinPrecio->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)
            ->getEndColor()->setRGB(self::ROJO_SUAVE);
        $h->getStyle("B2:B{$ultima}")->setConditionalStyles([$sinPrecio]);

        // Con el catálogo de la tienda adentro: listo para editar y volver a subir.
        $fila = 2;
        foreach ($productos as $p) {
            $h->setCellValueExplicit("A{$fila}", (string) ($p['barcode'] ?? ''), DataType::TYPE_STRING);
            $h->setCellValue("B{$fila}", $p['price']);
            $h->setCellValue("C{$fila}", $p['amount']);
            // Como TEXTO explícito: el catálogo es compartido y un nombre como
            // «=HYPERLINK(...)», puesto por otra tienda, se volvería fórmula.
            $h->setCellValueExplicit("D{$fila}", (string) ($p['name'] ?? ''), DataType::TYPE_STRING);
            $h->setCellValueExplicit("E{$fila}", (string) ($p['brand'] ?? ''), DataType::TYPE_STRING);
            $h->setCellValueExplicit("F{$fila}", (string) ($p['category'] ?? ''), DataType::TYPE_STRING);
            $fila++;
        }
    }

    private function hojaDeInstrucciones(Worksheet $h, ?string $tienda, array $categorias, array $columnas): void
    {
        $h->setTitle('Instrucciones');
        $h->getColumnDimension('A')->setWidth(22);
        $h->getColumnDimension('B')->setWidth(14);
        $h->getColumnDimension('C')->setWidth(62);
        $h->getColumnDimension('D')->setWidth(24);

        $h->setCellValue('A1', 'Cómo llenar tu catálogo'.($tienda ? " · {$tienda}" : ''));
        $h->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::MORADO);

        $pasos = [
            '1. Llena la hoja «Productos»: una fila por producto, desde la fila 2.',
            '2. Si el producto ya está en el catálogo de VeciPa\'Ya, basta con código, precio y cantidad.',
            '3. Si es nuevo, agrega también el nombre y elige la categoría de la lista.',
            '4. Guarda el archivo y súbelo en Productos › Subir un archivo.',
            '5. ¿Lo subes otra vez? Se actualiza lo que cambió: no se duplica nada.',
        ];
        foreach ($pasos as $i => $paso) {
            $h->setCellValue('A'.(3 + $i), $paso);
            $h->mergeCells('A'.(3 + $i).':D'.(3 + $i));
        }

        $inicio = 10;
        foreach (['Columna', '¿Obligatoria?', 'Qué poner', 'Ejemplo'] as $i => $t) {
            $c = chr(ord('A') + $i);
            $h->setCellValue("{$c}{$inicio}", $t);
        }
        $cab = $h->getStyle("A{$inicio}:D{$inicio}");
        $cab->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $cab->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::MORADO);

        $ejemplos = ['A' => '7702001000134', 'B' => '4600', 'C' => '12', 'D' => 'Leche entera Alquería 1 L',
            'E' => 'Alquería', 'F' => $categorias[0] ?? 'Lácteos y Huevos'];

        /*
         * EL EJEMPLO DEL REGISTRO INVIMA VIVE AQUÍ Y NO EN LA HOJA QUE SE
         * SUBE, a propósito.
         *
         * «INVIMA 2019M-0012345» puesto en la hoja Productos sería un
         * registro sanitario inventado entrando al catálogo COMPARTIDO de
         * la plataforma en cuanto alguien subiera la plantilla sin
         * borrarlo. Las instrucciones no se importan nunca.
         */
        $ejemplos += [
            'G' => 'Caja',
            'H' => '10 tabletas',
            'I' => 'INVIMA 2019M-0012345',
            'J' => 'No',
            'K' => 'Acetaminófen',
            'L' => '500 mg',
        ];

        $fila = $inicio + 1;
        foreach ($columnas as $col => [$titulo, , $obligatoria, $ayuda]) {
            $h->setCellValue("A{$fila}", $titulo);
            $h->setCellValue("B{$fila}", $obligatoria ? 'Sí' : 'Solo si es nuevo');
            $h->setCellValue("C{$fila}", $ayuda);
            $h->setCellValueExplicit("D{$fila}", $ejemplos[$col] ?? '', DataType::TYPE_STRING);
            $h->getStyle("A{$fila}:D{$fila}")->getAlignment()->setWrapText(true)
                ->setVertical(Alignment::VERTICAL_TOP);
            $h->getStyle("A{$fila}")->getFont()->setBold(true);
            if ($obligatoria) {
                $h->getStyle("B{$fila}")->getFont()->setBold(true)->getColor()->setRGB(self::MORADO);
            }
            $h->getStyle("A{$fila}:D{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($fila % 2 ? 'FFFFFF' : self::GRIS);
            $fila++;
        }

        $fila++;
        $h->setCellValue("A{$fila}", 'Consejo: también aceptamos el archivo que exporta tu punto de venta, con sus columnas «barras», «venta1» y «existencia».');
        $h->mergeCells("A{$fila}:D{$fila}");
        $h->getStyle("A{$fila}:D{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB(self::AMBAR);
        $h->getStyle("A{$fila}")->getAlignment()->setWrapText(true);
        $h->getRowDimension($fila)->setRowHeight(32);
    }

    /** Las categorías, en una hoja escondida: de ahí sale el desplegable. */
    /*
     * La columna A son las categorías y la B es Sí/No.
     *
     * En la MISMA hoja y no en una cuarta: `PlantillaDeCatalogoTest` fija
     * que el libro tiene exactamente tres --Productos, Instrucciones y
     * Listas-- y la fórmula del desplegable de categorías apunta a
     * `Listas!$A$2:$A$n`, así que la A no se puede mover.
     */
    private function hojaDeListas(Worksheet $h, array $categorias): void
    {
        $h->setTitle('Listas');
        $h->setCellValue('A1', 'Categorías');
        foreach ($categorias as $i => $nombre) {
            $h->setCellValue('A'.($i + 2), $nombre);
        }

        /* La B es el Sí/No de «requiere fórmula». Va en esta misma hoja y no
           en una cuarta porque el libro tiene que seguir teniendo tres. */
        $h->setCellValue('B1', 'Sí o no');
        $h->setCellValue('B2', 'Sí');
        $h->setCellValue('B3', 'No');
        $h->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Las categorías de las tiendas de este tipo, las que esta tienda ya usa
     * primero. Sin tipo, todas las activas. Son las que el importador acepta.
     */
    private function categorias(int $negocioId): array
    {
        $tipo = DB::table('business')->where('busines_id', $negocioId)->value('type');

        $usadas = DB::table('products_business as pb')
            ->join('products as p', 'p.products_id', '=', 'pb.products_id')
            ->join('category as c', 'c.category_id', '=', 'p.category_id')
            ->where('pb.busines_id', $negocioId)
            ->distinct()
            ->pluck('c.name')
            ->all();

        /* Activas (o sin estado) y del tipo de esta tienda o sin tipo: una
           categoría sin tipo la acepta cualquier tienda al importar. */
        /*
         * TRES CAMINOS PARA PERTENECER AL TIPO, y los tres hacen falta.
         *
         * La fuente de verdad es el PIVOTE `category_category_business`: es
         * lo que leen el endpoint del esquema y la validación al guardar, y
         * esta plantilla miraba sólo la columna `business_category_id`, que
         * quedó como herencia. Con las categorías de droguería sembradas en
         * el pivote, el desplegable habría salido sin ellas.
         *
         * Pero la columna se queda como segundo camino, y «sin clasificar»
         * como tercero: hay categorías antiguas que no están en el pivote, y
         * una sin tipo la acepta cualquier negocio al importar. Dejar sólo el
         * pivote las haría desaparecer de la plantilla sin que nadie lo
         * pidiera.
         */
        $delTipo = DB::table('category')
            ->where(fn ($q) => $q->where('state', 1)->orWhereNull('state'))
            ->when($tipo, fn ($q) => $q->where(fn ($w) => $w
                ->whereExists(fn ($e) => $e
                    ->selectRaw('1')
                    ->from('category_category_business as p')
                    ->whereColumn('p.category_id', 'category.category_id')
                    ->where('p.business_category_id', $tipo),
                )
                ->orWhere('business_category_id', $tipo)
                ->orWhereNull('business_category_id'),
            ))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $todas = array_values(array_unique(array_merge($usadas, $delTipo)));
        sort($todas, SORT_LOCALE_STRING | SORT_FLAG_CASE);

        return $todas;
    }

    private function productos(int $negocioId): array
    {
        return ProductBusiness::with('product.category')
            ->where('busines_id', $negocioId)
            ->get()
            ->filter(fn ($pb) => $pb->product !== null)
            ->map(fn ($pb) => [
                'barcode' => $pb->product->barcode,
                'price' => (float) $pb->price ?: null,
                'amount' => (int) $pb->amount,
                'name' => $pb->product->name,
                'brand' => $pb->product->brand,
                'category' => $pb->product->category?->name,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}

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
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
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
    /*
     * ==================================================================
     * LA PALETA ES LA DEL PANEL, NO UNA INVENTADA PARA EL EXCEL.
     *
     * Esto tenía sus propios colores --un lila frío `F5F3FA`-- mientras el
     * panel usa un fondo CÁLIDO `#f6f5f1`. Puestos uno al lado del otro no
     * parecían del mismo producto: el archivo que baja el tendero se veía
     * como de otra aplicación.
     *
     * Los valores salen tal cual de `EnviaYa_Aliados/src/styles/diseno.css`.
     * Si allí cambian, acá también; dos paletas para una marca es cómo se
     * consigue que nada combine.
     * ==================================================================
     */

    /** `--brand`. */
    private const MORADO = '1B1464';

    /** `--brand-soft`. */
    private const LILA = 'EEEBFF';

    /** `--bg`: cálido, no lila. */
    private const GRIS = 'F6F5F1';

    /** `--line`, para las rejillas. */
    private const LINEA = 'ECEAE4';

    /** `--muted`, para lo secundario. */
    private const TENUE = '5B5E6B';

    /** Una banda apenas visible para las filas pares. */
    private const BANDA = 'FBFAF8';

    private const AMBAR = 'FFF3D6';

    private const ROJO_SUAVE = 'FFE5E0';

    /** Filas con listas y formato: alcanza para una carga grande. */
    private const FILAS_MINIMAS = 2000;

    /**
     * El ejemplo de cada una de las seis columnas comunes a todos los oficios.
     *
     * Las demás lo traen de `ProductTypeSchema`, pegado a su campo. Antes
     * estaban TODOS acá, indexados por LETRA de columna, y al añadir contenido
     * y presentación las letras se corrieron: la hoja de instrucciones acabó
     * diciendo que el ejemplo de «Contenido» era «Caja» y el de «Necesita
     * frío», un registro INVIMA. El ejemplo tiene que viajar con el campo, no
     * con el sitio donde le toque caer.
     */
    private const EJEMPLOS_BASE = [
        'A' => '7702001000134',
        'B' => '4600',
        'C' => '12',
        'D' => 'Leche entera Alquería 1 L',
        'E' => 'Alquería',
        'F' => '',
    ];

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

        /*
         * UNA PLANTILLA VACÍA ABRE EN LA PORTADA, Y UNA CON PRODUCTOS NO.
         *
         * Abría siempre en «Productos», o sea en una rejilla en blanco: el
         * logo, los pasos, la guía de las columnas y la lista de categorías
         * estaban en la hoja de al lado y no los veía nadie, porque nadie
         * hace clic en una pestaña que no sabe que necesita.
         *
         * Con productos dentro es al revés: quien la baja para corregir
         * precios ya sabe llenarla y quiere ver su catálogo.
         */
        $libro->setActiveSheetIndex($conProductos ? 0 : 1);

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
            $columnas[$letra] = [
                $titulo, $ancho, $obligatoria, $ayuda, null,
                self::EJEMPLOS_BASE[$letra] ?? '',
            ];
        }

        /* Desde la G. Las extra son todas OPCIONALES a propósito: una
           drogueria que ya tiene su inventario en otro sistema tiene que
           poder subirlo hoy y completar lo demás después. */
        $letra = 'G';

        foreach (ProductTypeSchema::columnasDeCarga($tipo) as $campo => $def) {
            $columnas[$letra] = [
                $def['titulo'], $def['ancho'], false, $def['ayuda'], $campo,
                $def['ejemplo'] ?? '',
            ];
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

            /*
             * TODAS LAS CABECERAS IGUALES, Y LO OBLIGATORIO NO SE DICE CON
             * COLOR.
             *
             * Antes las tres obligatorias iban en morado sólido y las demás en
             * lila pálido, así que la fila 1 parecía dos tablas pegadas. Y el
             * color no servía para nada: cuando el tendero está escribiendo en
             * la fila 400 no ve la fila 1.
             *
             * Ahora lo obligatorio se marca DONDE SE TRABAJA --la celda vacía
             * se tiñe sola, más abajo-- y la cabecera sólo tiene que ser
             * legible y de la marca.
             *
             * El título NO lleva asterisco ni nada añadido: es lo que lee el
             * importador. «Precio *» se normalizaría a `precio_` y la columna
             * dejaría de reconocerse.
             */
            $estilo = $h->getStyle("{$col}1");
            $estilo->getFont()->setBold(true)->setSize(11)
                ->getColor()->setRGB('FFFFFF');
            $estilo->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(self::MORADO);
            $estilo->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);

            // La explicación también como comentario del encabezado: se ve al
            // pasar el mouse aunque no se esté editando.
            $nota = $h->getComment("{$col}1");
            $nota->getText()->createText(($obligatoria ? 'OBLIGATORIA. ' : 'OPCIONAL. ').$ayuda);
            $nota->setWidth('260pt')->setHeight('90pt');
        }

        $h->getRowDimension(1)->setRowHeight(34);
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
        /*
         * SE QUITÓ EL BLOQUE GRIS, que era lo que hacía que esto se viera
         * muerto.
         *
         * Pintaba de gris sólido desde la D hasta la última columna por DOS MIL
         * FILAS. En pantalla eso es un rectángulo apagado que ocupa casi toda
         * la hoja, y no comunicaba nada: su intención era decir «esto es
         * opcional», pero lo opcional ya lo dice la hoja de instrucciones y el
         * comentario de cada cabecera.
         *
         * En su lugar van franjas alternas apenas visibles, que es lo que hace
         * una tabla legible sin gritar. Se pintan por CONDICIONAL y no celda a
         * celda: dos mil filas con relleno explícito engordan el archivo y lo
         * vuelven lento de abrir.
         */
        $banda = new Conditional;
        $banda->setConditionType(Conditional::CONDITION_EXPRESSION)
            ->addCondition('MOD(ROW(),2)=0');
        $banda->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)
            ->getEndColor()->setRGB(self::BANDA);

        $h->getStyle("A2:{$finCol}{$ultima}")->setConditionalStyles([$banda]);

        /* Y una rejilla tenue, del color de las líneas del panel. */
        $h->getStyle("A1:{$finCol}{$ultima}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB(self::LINEA);

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

        /*
         * LO QUE FALTA SE TIÑE SOLO, Y SÓLO EN LA FILA QUE SE ESTÁ USANDO.
         *
         * Una fila vacía no se marca: la hoja abre limpia. En cuanto se
         * escribe algo en ella, las obligatorias que sigan en blanco se ponen
         * en rojo suave. Es el aviso puesto donde se trabaja, en vez de un
         * color en la fila 1 que no se ve desde la fila 400.
         *
         * Son exactamente las filas que el importador va a rechazar, así que
         * el archivo avisa antes de subirlo.
         */
        foreach (['A', 'B', 'C'] as $obligatoria) {
            $falta = new Conditional;
            $falta->setConditionType(Conditional::CONDITION_EXPRESSION)
                ->addCondition(
                    'AND(COUNTA($A2:$'.$finCol.'2)>0,'.$obligatoria.'2="")',
                );
            $falta->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)
                ->getEndColor()->setRGB(self::ROJO_SUAVE);

            $existentes = $h->getStyle("{$obligatoria}2:{$obligatoria}{$ultima}")
                ->getConditionalStyles();

            $h->getStyle("{$obligatoria}2:{$obligatoria}{$ultima}")
                ->setConditionalStyles(array_merge([$falta], $existentes));
        }

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

        /*
         * UNA BANDA DE MARCA CON EL LOGO, Y VA EN ESTA HOJA Y NO EN LA OTRA.
         *
         * En «Productos» no cabe: el importador lee los encabezados en la FILA
         * 1 --`WithHeadingRow`-- así que meter un logo encima correría la
         * cabecera y dejaría de reconocer las columnas. Y no sólo rompería la
         * plantilla nueva: también todos los archivos que los tenderos tienen
         * ya llenos a medias en el escritorio.
         *
         * Así que la marca vive acá, que además es la hoja que se abre
         * primero.
         */
        $h->getRowDimension(1)->setRowHeight(58);
        $h->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB(self::MORADO);

        $logo = resource_path('marca/logotipo.png');

        if (is_file($logo)) {
            $dibujo = new Drawing;
            $dibujo->setName("VeciPa'Ya");
            $dibujo->setPath($logo);
            $dibujo->setHeight(40);
            $dibujo->setOffsetX(10);
            $dibujo->setOffsetY(9);
            $dibujo->setCoordinates('A1');
            $dibujo->setWorksheet($h);
        }

        $h->setCellValue('B1', 'Cómo llenar tu catálogo'.($tienda ? " · {$tienda}" : ''));
        $h->mergeCells('B1:D1');
        $h->getStyle('B1')->getFont()->setBold(true)->setSize(15)
            ->getColor()->setRGB('FFFFFF');
        $h->getStyle('B1')->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);

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

        /* La categoría de ejemplo, de las que este negocio sí puede usar. */
        $columnas['F'][5] = $categorias[0] ?? '';

        $fila = $inicio + 1;
        foreach ($columnas as $col => [$titulo, , $obligatoria, $ayuda, $campo, $ejemplo]) {
            $h->setCellValue("A{$fila}", $titulo);

            /*
             * TRES ESTADOS, NO DOS.
             *
             * Esto decía «Sí» o «Solo si es nuevo», que valía cuando sólo
             * existían las seis columnas base: ahí lo no obligatorio era
             * nombre, marca y categoría, que en efecto sólo hacen falta
             * para un producto que todavía no está en el catálogo.
             *
             * Las del oficio --contenido, registro INVIMA, minutos de
             * preparación-- no son eso: son OPCIONALES siempre. Decirles
             * «solo si es nuevo» le dice al tendero que no las llene cuando
             * está corrigiendo, que es justo cuando más útil sería.
             */
            $h->setCellValue(
                "B{$fila}",
                $obligatoria ? 'Sí' : ($campo === null ? 'Solo si es nuevo' : 'Opcional'),
            );
            $h->setCellValue("C{$fila}", $ayuda);
            $h->setCellValueExplicit("D{$fila}", (string) $ejemplo, DataType::TYPE_STRING);
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

        /* Y las categorías de su oficio, que es lo que nadie encontraba. */
        $fila = $this->categoriasALaVista($h, $categorias, $fila + 1) + 1;

        $h->setCellValue("A{$fila}", 'Consejo: también aceptamos el archivo que exporta tu punto de venta, con sus columnas «barras», «venta1» y «existencia».');
        $h->mergeCells("A{$fila}:D{$fila}");
        $h->getStyle("A{$fila}:D{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB(self::AMBAR);
        $h->getStyle("A{$fila}")->getAlignment()->setWrapText(true);
        $h->getRowDimension($fila)->setRowHeight(32);
    }

    /** Las categorías, en una hoja escondida: de ahí sale el desplegable. */
    /**
     * LAS CATEGORÍAS, ESCRITAS DONDE SE VEN.
     *
     * Ya estaban en el desplegable de la columna «Categoría», pero la hoja que
     * las guarda está OCULTA y la flechita que se ve en la fila 1 es la del
     * filtro, no la de la lista: hay que pararse en una celda para que
     * aparezcan. Quien bajaba la plantilla no las encontraba y escribía la
     * categoría a mano, que es la primera causa de filas rechazadas.
     *
     * Acá salen todas de una vez, y son las del TIPO DE NEGOCIO: una droguería
     * ve sus doce y una tienda las suyas.
     */
    private function categoriasALaVista(Worksheet $h, array $categorias, int $desde): int
    {
        if ($categorias === []) {
            return $desde;
        }

        $h->setCellValue("A{$desde}", 'Categorías que puedes usar');
        $h->mergeCells("A{$desde}:D{$desde}");
        $h->getStyle("A{$desde}")->getFont()->setBold(true)->setSize(12)
            ->getColor()->setRGB(self::MORADO);

        $h->setCellValue('A'.($desde + 1), 'Elígelas del desplegable de la columna «Categoría». Escritas a mano suelen no coincidir, y la fila se rechaza.');
        $h->mergeCells('A'.($desde + 1).':D'.($desde + 1));
        $h->getStyle('A'.($desde + 1))->getFont()->setSize(10)
            ->getColor()->setRGB(self::TENUE);

        /* De a dos por fila: una lista de doce en una sola columna obliga a
           bajar, y lo que se quiere es verlas todas juntas. */
        $fila = $desde + 2;
        $col = 'A';

        foreach ($categorias as $nombre) {
            $h->setCellValue("{$col}{$fila}", '· '.$nombre);
            $h->getStyle("{$col}{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(self::LILA);
            $h->getStyle("{$col}{$fila}")->getAlignment()->setIndent(1);

            if ($col === 'A') {
                $col = 'C';
                $h->mergeCells("C{$fila}:D{$fila}");
            } else {
                $col = 'A';
                $fila++;
            }
        }

        return $col === 'A' ? $fila : $fila + 1;
    }

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

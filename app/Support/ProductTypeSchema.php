<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product\CarPartsProducts;
use App\Models\Product\GroceryProduct;
use App\Models\Product\PharmacyProduct;
use App\Models\Product\RestaurantProducts;

/**
 * Fuente única de verdad de los campos extra de producto por tipo de negocio.
 *
 * El tipo de negocio (business.type) referencia category_business:
 * 1=Tienda, 2=Farmacia, 3=Restaurante, 4=Repuestos. Cada tipo guarda sus
 * campos en su propia tabla (grocery_products, pharmacy_products, ...).
 *
 * Para agregar un tipo nuevo: definir aquí su entrada (modelo, relación en
 * Product y campos) y crear su migración/modelo. Controller y endpoint
 * /product/schema se adaptan solos.
 */
class ProductTypeSchema
{
    public const TYPE_GROCERY = 1;

    public const TYPE_PHARMACY = 2;

    public const TYPE_RESTAURANT = 3;

    public const TYPE_CAR_PARTS = 4;

    /**
     * ==================================================================
     * LO QUE TIENE CUALQUIER PRODUCTO, SEA DEL OFICIO QUE SEA.
     *
     * Viven en `products` --el catalogo COMPARTIDO-- y no en la tabla del
     * tipo. La regla, que antes no estaba escrita en ningun sitio:
     *
     *   · aqui, lo que describe el articulo IGUAL en todos los oficios;
     *   · en la tabla del tipo, lo que SOLO ese oficio pregunta;
     *   · en `products_business`, lo que cambia por tienda.
     *
     * Los tres estaban repartidos por tipo con tres nombres distintos
     * --`pharmacy.content`, `grocery.size`, `restaurant.portion_size`--
     * para la misma pregunta: cuanto trae.
     * ==================================================================
     *
     * @var array<string, array<string, mixed>>
     */
    private const COMUNES = [
        'content' => [
            'rules' => ['nullable', 'string', 'max:80'],
            'label' => 'Contenido',
            'input' => 'text',
            'columna' => 'Contenido',
            'ancho' => 18,
            'alias' => ['contenido', 'content', 'tamano', 'tamaño', 'size'],
            'ejemplo' => '500 g',
            'ayuda' => 'Cuanto trae: «500 g», «10 tabletas», «1 L», «Personal».',
        ],
        'presentation' => [
            'rules' => ['nullable', 'string', 'max:60'],
            'label' => 'Presentación',
            'input' => 'text',
            'columna' => 'Presentación',
            'ancho' => 16,
            'alias' => ['presentacion', 'presentation', 'envase', 'empaque'],
            'ejemplo' => 'Bolsa',
            'ayuda' => 'En que viene: bolsa, caja, lata, frasco, blíster, tubo, sobre…',
        ],
        'requiere_frio' => [
            'rules' => ['nullable', 'boolean'],
            'label' => 'Necesita frío',
            'input' => 'boolean',
            'columna' => 'Necesita frío',
            'ancho' => 16,
            'alias' => ['requiere_frio', 'frio', 'refrigerado', 'cadena_de_frio'],
            'ejemplo' => 'No',
            'ayuda' => 'Sí o No. Leche, yogurt, huevos, helado, insulina…',
        ],
    ];

    private const SCHEMAS = [
        self::TYPE_GROCERY => [
            'type_name' => 'grocery',
            'relation' => 'grocery',
            'model' => GroceryProduct::class,

            /*
             * SIN CAMPOS PROPIOS, Y NO ES QUE LE FALTEN.
             *
             * Tenía tres y los tres sobraban:
             *
             *   · `brand` duplicaba `products.brand`. Y como el formulario
             *     escribía en uno y la carga masiva en el otro, la marca que
             *     se tecleaba no se podía buscar.
             *   · `size` es el CONTENIDO, que ahora es común: la misma
             *     pregunta que `pharmacy.content` y `restaurant.portion_size`.
             *   · `expiration_date` es del LOTE, y vive en
             *     `products_business` desde su propia migración.
             *
             * Lo que vende una tienda de barrio es «cosas», y «cosa» es lo
             * que `products` ya describe. Un tipo delgado no es un tipo mal
             * hecho: es el que no necesita preguntar nada aparte.
             */
            'fields' => [],
        ],

        self::TYPE_PHARMACY => [
            'type_name' => 'pharmacy',
            'relation' => 'pharmacy',
            'model' => PharmacyProduct::class,

            /*
             * CUATRO, Y SON LOS ÚNICOS QUE UNA DROGUERÍA PREGUNTA APARTE.
             *
             * De los once campos de la ficha de un medicamento, nueve son de
             * cualquier producto --nombre, marca, código, imagen, categoría,
             * contenido, envase, precio y existencias-- y ya vivían en
             * `products` o en `products_business`. Estos cuatro no se le
             * piden a un bulto de arroz.
             *
             * `content` y `presentation` salieron de aquí a `products`: el
             * mismo frasco trae el mismo contenido lo venda quien lo venda.
             * `expiration_date` quedó deprecado por el vencimiento del lote.
             */
            'fields' => [
                'registro_invima' => [
                    'rules' => ['nullable', 'string', 'max:40'],
                    'label' => 'Registro INVIMA',
                    'input' => 'text',
                    'columna' => 'Registro INVIMA',
                    'ancho' => 24,
                    'alias' => ['registro_invima', 'invima', 'registro_sanitario', 'registro'],
                    'ejemplo' => 'INVIMA 2019M-0012345',
                    'ayuda' => 'El registro sanitario, si el producto lo lleva. Tal cual sale en el empaque.',
                ],
                'requiere_formula' => [
                    'rules' => ['nullable', 'boolean'],
                    'label' => 'Requiere fórmula médica',
                    'input' => 'boolean',
                    'columna' => 'Requiere fórmula',
                    'ancho' => 20,
                    'alias' => ['requiere_formula', 'formula', 'formula_medica', 'receta'],
                    'ejemplo' => 'No',
                    'ayuda' => 'Sí o No. Si lo dejas vacío se entiende que NO la requiere.',
                ],
                'active_ingredient' => [
                    'rules' => ['nullable', 'string', 'max:255'],
                    'label' => 'Principio activo',
                    'input' => 'text',
                    'columna' => 'Principio activo',
                    'ancho' => 26,
                    'alias' => ['principio_activo', 'active_ingredient', 'principio'],
                    'ejemplo' => 'Acetaminofén',
                    'ayuda' => 'Opcional, y sólo para medicamentos: «acetaminófen», «ibuprofeno».',
                ],
                'dosage' => [
                    'rules' => ['nullable', 'string', 'max:100'],
                    'label' => 'Dosis o concentración',
                    'input' => 'text',
                    'columna' => 'Dosis',
                    'ancho' => 14,
                    'alias' => ['dosis', 'dosage', 'concentracion'],
                    'ejemplo' => '500 mg',
                    'ayuda' => 'Opcional. La concentración: «500 mg». No es el contenido.',
                ],
            ],
        ],

        self::TYPE_RESTAURANT => [
            'type_name' => 'restaurant',
            'relation' => 'restaurant',
            'model' => RestaurantProducts::class,

            /*
             * `food_type` SALIÓ: era lo que ya hace la categoría --Almuerzos,
             * Comidas Rápidas, Postres-- y dos clasificaciones para la misma
             * cosa acaban diciendo cosas distintas. `portion_size` se fue al
             * contenido común.
             *
             * Y ENTRARON TRES. `minutos_preparacion` es el más importante de
             * todo este archivo: la plataforma entera promete tiempos de
             * entrega y no había dónde decir que una bandeja paisa tarda
             * veinticinco minutos y una gaseosa ninguno. Sin eso la promesa es
             * una adivinanza.
             */
            'fields' => [
                'minutos_preparacion' => [
                    'rules' => ['nullable', 'integer', 'min:0', 'max:240'],
                    'label' => 'Minutos de preparación',
                    'input' => 'number',
                    'columna' => 'Minutos de preparación',
                    'ancho' => 22,
                    'alias' => ['minutos_preparacion', 'minutos', 'preparacion', 'tiempo'],
                    'ejemplo' => '25',
                    'ayuda' => 'Cuánto tarda en salir de la cocina. Una gaseosa, 0.',
                ],
                'acompanamientos' => [
                    'rules' => ['nullable', 'string', 'max:255'],
                    'label' => 'Con qué viene',
                    'input' => 'text',
                    'columna' => 'Con qué viene',
                    'ancho' => 30,
                    'alias' => ['acompanamientos', 'acompañamientos', 'incluye', 'viene_con'],
                    'ejemplo' => 'Arroz, ensalada y jugo',
                    'ayuda' => '«Arroz, ensalada y jugo». Es lo que hace que un corrientazo sea un corrientazo.',
                ],
                'es_vegetariano' => [
                    'rules' => ['nullable', 'boolean'],
                    'label' => 'Vegetariano',
                    'input' => 'boolean',
                    'columna' => 'Vegetariano',
                    'ancho' => 14,
                    'alias' => ['es_vegetariano', 'vegetariano'],
                    'ejemplo' => 'No',
                    'ayuda' => 'Sí o No. No es lo mismo que vegano, y se pide mucho más.',
                ],
                'is_vegan' => [
                    'rules' => ['nullable', 'boolean'],
                    'label' => 'Vegano',
                    'input' => 'boolean',
                    'columna' => 'Vegano',
                    'ancho' => 12,
                    'alias' => ['es_vegano', 'vegano', 'is_vegan'],
                    'ejemplo' => 'No',
                    'ayuda' => 'Sí o No. Sin nada de origen animal.',
                ],
                'is_gluten_free' => [
                    'rules' => ['nullable', 'boolean'],
                    'label' => 'Sin gluten',
                    'input' => 'boolean',
                    'columna' => 'Sin gluten',
                    'ancho' => 14,
                    'alias' => ['sin_gluten', 'is_gluten_free', 'gluten'],
                    'ejemplo' => 'No',
                    'ayuda' => 'Sí o No.',
                ],
                'allergens' => [
                    'rules' => ['nullable', 'string', 'max:255'],
                    'label' => 'Alérgenos',
                    'input' => 'text',
                    'columna' => 'Alérgenos',
                    'ancho' => 24,
                    'alias' => ['alergenos', 'alérgenos', 'allergens'],
                    'ejemplo' => 'Maní, leche',
                    'ayuda' => 'Maní, leche, huevo, mariscos… Lo que alguien necesita saber antes de pedir.',
                ],
            ],
        ],

        self::TYPE_CAR_PARTS => [
            'type_name' => 'carParts',
            'relation' => 'carPart',
            'model' => CarPartsProducts::class,

            /*
             * `brand` SALIÓ por lo mismo que en tienda: duplicaba
             * `products.brand`.
             *
             * Y `model` + `year` + `compatibility` SE FUNDIERON EN UNO. Los
             * tres respondían a la misma pregunta --para qué carro sirve--
             * repartida en tres textos libres que nadie puede cruzar: buscar
             * «Spark 2012» no acertaba en ninguno de los tres. Uno solo que
             * diga «Chevrolet Spark 2010-2016, Aveo 2008-2014» es más honesto
             * y más útil.
             *
             * La compatibilidad DE VERDAD --elegir marca, línea y año en tres
             * desplegables-- es muchos a muchos y necesita su propia tabla de
             * vehículos con un pivote. Eso se hace cuando haya una tienda de
             * repuestos de verdad usándolo; mientras tanto, un texto honesto
             * en vez de tres que fingen estar estructurados.
             */
            'fields' => [
                'oem_code' => [
                    'rules' => ['nullable', 'string', 'max:60'],
                    'label' => 'Código OEM',
                    'input' => 'text',
                    'columna' => 'Código OEM',
                    'ancho' => 20,
                    'alias' => ['oem_code', 'oem', 'codigo_oem', 'numero_de_parte'],
                    'ejemplo' => '96405131',
                    'ayuda' => 'El código del fabricante. Es la identidad de la pieza, como el código de barras.',
                ],
                'compatibility' => [
                    'rules' => ['nullable', 'string', 'max:255'],
                    'label' => 'Para qué vehículos sirve',
                    'input' => 'text',
                    'columna' => 'Compatibilidad',
                    'ancho' => 34,
                    'alias' => ['compatibilidad', 'compatibility', 'aplicacion', 'vehiculos'],
                    'ejemplo' => 'Chevrolet Spark 2010-2016',
                    'ayuda' => '«Chevrolet Spark 2010-2016, Aveo 2008-2014». Marca, línea y años.',
                ],
                'posicion' => [
                    'rules' => ['nullable', 'string', 'max:40'],
                    'label' => 'Posición',
                    'input' => 'text',
                    'columna' => 'Posición',
                    'ancho' => 16,
                    'alias' => ['posicion', 'position', 'ubicacion'],
                    'ejemplo' => 'Delantero izquierdo',
                    'ayuda' => 'Delantero, trasero, izquierdo, derecho. Es el error clásico al comprar.',
                ],
                'condicion' => [
                    'rules' => ['nullable', 'string', 'max:30'],
                    'label' => 'Condición',
                    'input' => 'text',
                    'columna' => 'Condición',
                    'ancho' => 16,
                    'alias' => ['condicion', 'condition', 'tipo_pieza'],
                    'ejemplo' => 'Original',
                    'ayuda' => 'Original, genérico o remanufacturado. Cambia el precio al doble.',
                ],
            ],
        ],
    ];

    /**
     * Las columnas extra que este tipo añade a la plantilla de carga.
     *
     * Vacío para quien no declare `columna`, que es lo que mantiene la
     * plantilla de las tiendas igual que estaba.
     *
     * @return array<string, array{titulo: string, ancho: int, ayuda: string, input: string}>
     */
    public static function columnasDeCarga(?int $type): array
    {
        $salida = [];

        /*
         * LOS COMUNES VAN PRIMERO, Y PARA TODOS LOS TIPOS.
         *
         * Contenido y envase los llena cualquier oficio, asi que el
         * archivo de una tienda tambien los trae. Era lo que faltaba para
         * que la carga masiva sirviera de algo fuera de farmacia: tienda,
         * restaurante y repuestos tenian CERO columnas extra.
         */
        foreach (self::COMUNES as $campo => $def) {
            $salida[$campo] = [
                'titulo' => $def['columna'],
                'ancho' => $def['ancho'],
                'ayuda' => $def['ayuda'],
                'input' => $def['input'],
                'ejemplo' => $def['ejemplo'] ?? '',
                'comun' => true,
            ];
        }

        foreach (self::SCHEMAS[$type]['fields'] ?? [] as $campo => $def) {
            if (! isset($def['columna'])) {
                continue;
            }

            $salida[$campo] = [
                'titulo' => $def['columna'],
                'ancho' => $def['ancho'] ?? 20,
                'ayuda' => $def['ayuda'] ?? '',
                'input' => $def['input'] ?? 'text',
                'ejemplo' => $def['ejemplo'] ?? '',
                'comun' => false,
            ];
        }

        return $salida;
    }

    /**
     * Cómo puede venir escrito cada campo en el encabezado del archivo.
     *
     * El nombre técnico entra siempre como alias de sí mismo: quien exporte
     * desde su propio sistema con ese nombre también acierta.
     *
     * @return array<string, list<string>>
     */
    public static function aliasDeCarga(?int $type): array
    {
        $salida = [];

        foreach (self::COMUNES as $campo => $def) {
            $salida[$campo] = array_values(array_unique(
                array_merge([$campo], $def['alias']),
            ));
        }

        foreach (self::SCHEMAS[$type]['fields'] ?? [] as $campo => $def) {
            if (! isset($def['columna'])) {
                continue;
            }

            $salida[$campo] = array_values(array_unique(
                array_merge([$campo], $def['alias'] ?? []),
            ));
        }

        return $salida;
    }

    /**
     * Los campos comunes, los que viven en `products`.
     *
     * @return list<string>
     */
    public static function camposComunes(): array
    {
        return array_keys(self::COMUNES);
    }

    /**
     * Reglas de validacion de los comunes, para el alta y la edicion.
     *
     * @return array<string, list<string>>
     */
    public static function reglasComunes(): array
    {
        $salida = [];

        foreach (self::COMUNES as $campo => $def) {
            $salida[$campo] = $def['rules'];
        }

        return $salida;
    }

    /**
     * Los comunes como los pinta un formulario, igual que `forApi`.
     *
     * @return list<array<string, mixed>>
     */
    public static function comunesParaApi(): array
    {
        $salida = [];

        foreach (self::COMUNES as $campo => $def) {
            $salida[] = [
                'name' => $campo,
                'label' => $def['label'],
                'input' => $def['input'],
                'required' => in_array('required', $def['rules'], true),
            ];
        }

        return $salida;
    }

    public static function has(?int $type): bool
    {
        return $type !== null && isset(self::SCHEMAS[$type]);
    }

    /** Nombres de los campos extra del tipo dado. */
    public static function fieldNames(int $type): array
    {
        return array_keys(self::SCHEMAS[$type]['fields'] ?? []);
    }

    /** Reglas de validación de los campos extra del tipo dado. */
    public static function rules(int $type): array
    {
        return array_map(
            fn (array $field) => $field['rules'],
            self::SCHEMAS[$type]['fields'] ?? [],
        );
    }

    /**
     * Reglas para actualización parcial: igual que rules() pero sin exigir
     * los campos required (solo se validan si vienen en el request).
     */
    public static function updateRules(int $type): array
    {
        return array_map(
            fn (array $rules) => array_map(
                fn ($rule) => $rule === 'required' ? 'sometimes' : $rule,
                $rules,
            ),
            self::rules($type),
        );
    }

    /**
     * Campos que pertenecen a OTROS tipos (para rechazarlos explícitamente
     * en vez de ignorarlos en silencio). Los nombres compartidos entre tipos
     * (brand, expiration_date) no cuentan como ajenos.
     */
    public static function foreignFieldNames(int $type): array
    {
        $own = self::fieldNames($type);
        $all = [];

        foreach (self::SCHEMAS as $schema) {
            $all = array_merge($all, array_keys($schema['fields']));
        }

        return array_values(array_diff(array_unique($all), $own));
    }

    /** Nombre de la relación en el modelo Product (grocery, pharmacy, ...). */
    public static function relation(int $type): ?string
    {
        return self::SCHEMAS[$type]['relation'] ?? null;
    }

    /** Todas las relaciones de subtipo, para eager loading. */
    public static function allRelations(): array
    {
        return array_column(self::SCHEMAS, 'relation');
    }

    /** Clase del modelo de subtipo del tipo dado. */
    public static function model(int $type): ?string
    {
        return self::SCHEMAS[$type]['model'] ?? null;
    }

    /**
     * Los valores extra del producto dado según su tipo, como array
     * campo => valor (null si el producto no tiene fila de subtipo).
     */
    public static function extraFor(int $type, $product): ?array
    {
        $relation = self::relation($type);

        if (! $relation || ! $product->{$relation}) {
            return null;
        }

        return $product->{$relation}->only(self::fieldNames($type));
    }

    /**
     * Descripción del formulario para el cliente (endpoint /product/schema):
     * cada campo con etiqueta, tipo de input y si es obligatorio.
     */
    /**
     * Descripcion del formulario para el cliente.
     *
     * `common` ES UNA CLAVE NUEVA Y ADITIVA: una app vieja que no la
     * conozca la ignora y sigue pintando `fields` como siempre. Lleva los
     * campos que viven en `products` --contenido, envase, frio-- que antes
     * iban dentro de `fields` repartidos por tipo.
     */
    public static function forApi(int $type): array
    {
        $fields = [];

        foreach (self::SCHEMAS[$type]['fields'] ?? [] as $name => $field) {
            $fields[] = [
                'name' => $name,
                'label' => $field['label'],
                'input' => $field['input'],
                'required' => in_array('required', $field['rules'], true),
            ];
        }

        return [
            'business_type' => $type,
            'type_name' => self::SCHEMAS[$type]['type_name'] ?? null,
            'fields' => $fields,
            'common' => self::comunesParaApi(),

            /*
             * LAS COLUMNAS QUE DE VERDAD TRAE SU EXCEL.
             *
             * El panel enseñaba «basta con tres columnas: código, precio y
             * cantidad» a todos los oficios, y desde que la plantilla es por
             * tipo eso es falso: la de una droguería trae trece y la de un
             * restaurante quince. El archivo salía bien y el texto de al lado
             * decía otra cosa, que es la peor combinación: quien lo lee rellena
             * tres columnas y deja las demás vacías sin saber que existen.
             *
             * Son los TÍTULOS del Excel, no las etiquetas del formulario:
             * «Requiere fórmula» es la columna y «Requiere fórmula médica» es
             * el campo. Si el panel pinta la etiqueta, el tendero busca en la
             * hoja una columna que no se llama así.
             */
            'columnas' => array_values(array_map(
                fn (array $c) => $c['titulo'],
                self::columnasDeCarga($type),
            )),
        ];
    }
}

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

    private const SCHEMAS = [
        self::TYPE_GROCERY => [
            'type_name' => 'grocery',
            'relation' => 'grocery',
            'model' => GroceryProduct::class,
            'fields' => [
                'brand' => ['rules' => ['nullable', 'string', 'max:255'], 'label' => 'Marca', 'input' => 'text'],
                'size' => ['rules' => ['nullable', 'string', 'max:100'], 'label' => 'Tamaño / Presentación', 'input' => 'text'],
                'expiration_date' => ['rules' => ['nullable', 'date'], 'label' => 'Fecha de vencimiento', 'input' => 'date'],
            ],
        ],

        self::TYPE_PHARMACY => [
            'type_name' => 'pharmacy',
            'relation' => 'pharmacy',
            'model' => PharmacyProduct::class,
            /*
             * LO QUE UNA DROGUERÍA DE BARRIO SÍ LLENA.
             *
             * `active_ingredient` y `dosage` eran OBLIGATORIOS, y esa es la
             * razón de que `pharmacy_products` esté vacía: para dar de alta
             * un pañal o un frasco de alcohol había que inventarse un
             * principio activo. Pasan a opcionales; se siguen pidiendo, pero
             * no bloquean.
             *
             * `content` es «10 tabletas», «500 ml»: cuánto trae el envase.
             * NO es `dosage`, que es la concentración —un acetaminofén es de
             * 500 mg y la caja trae 10—. Meterlos en el mismo campo hace que
             * la ficha diga «Dosis: 10 tabletas», que es falso.
             *
             * `presentation` se queda en TEXTO LIBRE y no en lista cerrada:
             * una droguería vende en caja, frasco, blíster, sobre, ampolla,
             * tubo, óvulo y jeringa, y una lista que se queda corta obliga a
             * mentir en la celda.
             *
             * `expiration_date` ya NO está: se mudó a `products_business`
             * porque el atún de una tienda vence en marzo y el de la otra en
             * septiembre. Tenerlo en los dos sitios es cómo se consigue que
             * digan cosas distintas.
             */
            /*
             * `columna`, `alias`, `ancho` y `ayuda` son para la CARGA MASIVA,
             * y son OPCIONALES: un tipo que no las declara no añade ni una
             * celda a su plantilla ni un alias a su lector.
             *
             * Por eso tienda, restaurante y repuestos siguen con el archivo
             * de seis columnas exacto que tenían, sin que haya un `if` por
             * tipo de negocio en el importador ni en la plantilla.
             */
            'fields' => [
                'presentation' => [
                    'rules' => ['nullable', 'string', 'max:255'],
                    'label' => 'Presentación',
                    'input' => 'text',
                    'columna' => 'Presentación',
                    'ancho' => 18,
                    'alias' => ['presentacion', 'presentation', 'empaque'],
                    'ayuda' => 'Cómo viene: caja, frasco, blíster, sobre, tubo, ampolla, unidad…',
                ],
                'content' => [
                    'rules' => ['nullable', 'string', 'max:80'],
                    'label' => 'Contenido',
                    'input' => 'text',
                    'columna' => 'Contenido',
                    'ancho' => 18,
                    'alias' => ['contenido', 'content', 'cantidad_contenido'],
                    'ayuda' => 'Cuánto trae: «10 tabletas», «500 ml», «30 cápsulas». No es la dosis.',
                ],
                'registro_invima' => [
                    'rules' => ['nullable', 'string', 'max:40'],
                    'label' => 'Registro INVIMA',
                    'input' => 'text',
                    'columna' => 'Registro INVIMA',
                    'ancho' => 24,
                    'alias' => ['registro_invima', 'invima', 'registro_sanitario', 'registro'],
                    'ayuda' => 'El registro sanitario, si el producto lo lleva. Tal cual sale en el empaque.',
                ],
                'requiere_formula' => [
                    'rules' => ['nullable', 'boolean'],
                    'label' => 'Requiere fórmula médica',
                    'input' => 'boolean',
                    'columna' => 'Requiere fórmula',
                    'ancho' => 20,
                    'alias' => ['requiere_formula', 'formula', 'formula_medica', 'receta'],
                    'ayuda' => 'Sí o No. Si lo dejas vacío se entiende que NO la requiere.',
                ],
                'active_ingredient' => [
                    'rules' => ['nullable', 'string', 'max:255'],
                    'label' => 'Principio activo',
                    'input' => 'text',
                    'columna' => 'Principio activo',
                    'ancho' => 26,
                    'alias' => ['principio_activo', 'active_ingredient', 'principio'],
                    'ayuda' => 'Opcional, y sólo para medicamentos: «acetaminófen», «ibuprofeno».',
                ],
                'dosage' => [
                    'rules' => ['nullable', 'string', 'max:100'],
                    'label' => 'Dosis o concentración',
                    'input' => 'text',
                    'columna' => 'Dosis',
                    'ancho' => 14,
                    'alias' => ['dosis', 'dosage', 'concentracion'],
                    'ayuda' => 'Opcional. La concentración: «500 mg». No es el contenido.',
                ],
            ],
        ],

        self::TYPE_RESTAURANT => [
            'type_name' => 'restaurant',
            'relation' => 'restaurant',
            'model' => RestaurantProducts::class,
            'fields' => [
                'food_type' => ['rules' => ['required', 'string', 'max:255'], 'label' => 'Tipo de comida', 'input' => 'text'],
                'portion_size' => ['rules' => ['nullable', 'string', 'max:255'], 'label' => 'Tamaño de la porción', 'input' => 'text'],
                'is_vegan' => ['rules' => ['nullable', 'boolean'], 'label' => 'Vegano', 'input' => 'boolean'],
                'is_gluten_free' => ['rules' => ['nullable', 'boolean'], 'label' => 'Libre de gluten', 'input' => 'boolean'],
                'allergens' => ['rules' => ['nullable', 'string', 'max:255'], 'label' => 'Alérgenos', 'input' => 'text'],
            ],
        ],

        self::TYPE_CAR_PARTS => [
            'type_name' => 'car_parts',
            'relation' => 'carPart',
            'model' => CarPartsProducts::class,
            'fields' => [
                'brand' => ['rules' => ['nullable', 'string', 'max:255'], 'label' => 'Marca', 'input' => 'text'],
                'model' => ['rules' => ['required', 'string', 'max:255'], 'label' => 'Modelo del vehículo', 'input' => 'text'],
                'year' => ['rules' => ['nullable', 'integer', 'between:1950,2100'], 'label' => 'Año', 'input' => 'number'],
                'oem_code' => ['rules' => ['nullable', 'string', 'max:255'], 'label' => 'Código OEM', 'input' => 'text'],
                'compatibility' => ['rules' => ['nullable', 'string', 'max:255'], 'label' => 'Compatibilidad', 'input' => 'text'],
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

        foreach (self::SCHEMAS[$type]['fields'] ?? [] as $campo => $def) {
            if (! isset($def['columna'])) {
                continue;
            }

            $salida[$campo] = [
                'titulo' => $def['columna'],
                'ancho' => $def['ancho'] ?? 20,
                'ayuda' => $def['ayuda'] ?? '',
                'input' => $def['input'] ?? 'text',
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
        ];
    }
}

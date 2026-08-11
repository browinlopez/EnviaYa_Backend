<?php

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
            'fields' => [
                'active_ingredient' => ['rules' => ['required', 'string', 'max:255'], 'label' => 'Principio activo', 'input' => 'text'],
                'dosage' => ['rules' => ['required', 'string', 'max:100'], 'label' => 'Dosis / Concentración', 'input' => 'text'],
                'presentation' => ['rules' => ['nullable', 'string', 'max:255'], 'label' => 'Presentación', 'input' => 'text'],
                'expiration_date' => ['rules' => ['nullable', 'date'], 'label' => 'Fecha de vencimiento', 'input' => 'date'],
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

        if (!$relation || !$product->{$relation}) {
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

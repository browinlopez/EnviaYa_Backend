<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use App\Support\ProductTypeSchema;
use App\Support\UnidadDeVenta;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Precio, existencias y disponibilidad de un producto.
 */
class ActualizarProductoRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend. Repetirlo aqui seria una segunda fuente de verdad sobre
     * quien puede que, y la que se olvide de actualizar sera la que mande.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->reglasDelTipo(), [
            'price' => 'nullable|numeric|min:0',
            // Lo que le costó a la tienda. Opcional: sin él la cuenta lo avisa.
            'costo' => 'nullable|numeric|min:0|max:100000000',
            'amount' => 'nullable|integer|min:0',
            /*
             * COMO LO VENDE ESTA TIENDA. Por unidad o al peso --kilo, libra o
             * gramo-- y es del par tienda-producto: la misma referencia va
             * empacada aca y al peso en la de enfrente.
             *
             * Con una unidad de peso, `price` pasa a significar «precio por
             * esa unidad» y las existencias viven en `stock_weight`, que
             * admite decimales. `amount` se queda para lo que se vende por
             * unidad: migrarlo a decimal habria cambiado en silencio el
             * significado de veintidos sitios.
             */
            'sale_unit' => 'nullable|string|in:'.implode(',', UnidadDeVenta::TODAS),
            'stock_weight' => 'nullable|numeric|min:0|max:100000',
            // Hasta 5.000: en gramos, cien es casi nada.
            'min_qty' => 'nullable|numeric|min:0.001|max:5000',
            'step_qty' => 'nullable|numeric|min:0.001|max:5000',
            // «Se puede pedir por valor»: $1.500 de queso.
            'por_valor' => 'nullable|boolean',
            'expiration_date' => 'nullable|date',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer|exists:category,category_id',
            'state' => 'nullable|boolean',
        ]);
    }

    /**
     * Los datos de su TIPO de negocio (alérgenos y minutos de preparación en
     * un restaurante; INVIMA y fórmula en una droguería) y los comunes a
     * todos (contenido, presentación, si necesita frío). Todos opcionales:
     * se cambia lo que llega. Si el producto lo venden otros negocios, el
     * controlador los rechaza: son del catálogo compartido.
     */
    private function reglasDelTipo(): array
    {
        $tipo = (int) ($this->attributes->get('negocio')->type ?? 0);
        $reglas = ProductTypeSchema::reglasComunes();

        if ($tipo && ProductTypeSchema::has($tipo)) {
            $reglas = array_merge($reglas, ProductTypeSchema::updateRules($tipo));
        }

        // Opcionales y borrables: mandar null deja el campo vacío.
        return array_map(
            fn (array $r) => array_values(array_unique(array_merge(
                ['nullable'],
                array_filter($r, fn ($x) => $x !== 'required' && $x !== 'sometimes'),
            ))),
            $reglas,
        );
    }
}

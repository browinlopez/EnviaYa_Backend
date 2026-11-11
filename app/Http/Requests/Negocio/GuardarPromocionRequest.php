<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use App\Models\Promotion;
use App\Services\PromocionesDeLaTienda;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear o corregir una promocion de la tienda.
 *
 * Las mismas reglas para las dos rutas, que ya lo eran: el controlador las
 * tenia en `reglas()` y `mensajes()` y las llamaba desde `store()` y desde
 * `update()`. Aca dejan de ser privadas de un controlador y pasan a ser lo
 * que son: el contrato de entrada de ese endpoint.
 */
class GuardarPromocionRequest extends FormRequest
{
    /*
     * El permiso lo resolvio el middleware `negocio`. Y el negocio NO viene
     * en el cuerpo: lo pone el middleware, asi que aqui no hay nada que
     * validar sobre el.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'texto' => 'required|string|min:5|max:'.PromocionesDeLaTienda::MAXIMO_CARACTERES,
            'productos' => 'sometimes|array|max:20',
            'productos.*' => 'integer|exists:products,products_id',
            /*
             * `hasta` admite DOS cosas, y por eso no es un `in:` a secas.
             *
             * Una de las palabras enlatadas —«semana», «fin_de_semana»— que
             * es lo que manda la app publicada en las tiendas y lo que hay
             * que seguir aceptando, o una fecha, que es lo que manda la
             * pantalla nueva cuando el tendero elige día.
             *
             * Resolverlo con un cierre y no con dos campos distintos evita
             * tener `hasta` y `hasta_el` significando lo mismo, que es la
             * clase de pareja que acaba desincronizada.
             */
            'hasta' => ['sometimes', 'nullable', function ($campo, $valor, $falla) {
                if (in_array($valor, PromocionesDeLaTienda::PLAZOS, true)) {
                    return;
                }

                if (strtotime((string) $valor) === false) {
                    $falla('No entendimos hasta cuándo vale la promoción.');
                }
            }],

            'desde' => 'sometimes|nullable|date',

            /*
             * CUÁNTAS VECES SE PUEDE USAR. Null o ausente = sin tope, que es
             * como se han comportado todas hasta hoy.
             *
             * El mínimo es 1 y no 0: una promoción con cero usos disponibles
             * nace agotada, y eso no es un límite, es no mandarla.
             */
            'max_usos' => 'sometimes|nullable|integer|min:1|max:100000',

            /*
             * LA REGLA, que es lo que convierte el aviso en un descuento.
             *
             * `porcentaje` viaja como entero de 1 a 70. No de 0 a 100: un
             * 100% es regalar el producto, y un 0% es una promoción que no
             * promociona. El tope de 70 es la barrera contra el error de dedo
             * —escribir 90 queriendo 9— sobre algo que cobra de su bolsillo.
             */
            'tipo' => 'sometimes|in:'.implode(',', Promotion::TIPOS),
            'porcentaje' => 'required_if:tipo,'.Promotion::PORCENTAJE.'|nullable|integer|min:1|max:70',
            'lleva' => 'required_if:tipo,'.Promotion::NXM.'|nullable|integer|min:2|max:12',
            'paga' => 'required_if:tipo,'.Promotion::NXM.'|nullable|integer|min:1|lt:lleva',
        ];
    }

    public function messages(): array
    {
        return [
            'texto.required' => 'Escribe qué quieres ofrecer.',
            'texto.min' => 'Escribe un poco más para que se entienda.',
            'texto.max' => 'La promoción es muy larga; en el teléfono se cortaría.',
            'porcentaje.max' => 'Un descuento mayor al 70% suele ser un error de dedo. Escríbelo en el texto si de verdad es así.',
            'paga.lt' => 'Tiene que pagar menos de lo que lleva; si no, no hay promoción.',
        ];
    }
}

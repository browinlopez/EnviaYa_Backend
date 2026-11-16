<?php

declare(strict_types=1);

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La ficha de la tienda, editada por su duenno.
 */
class ActualizarMiNegocioRequest extends FormRequest
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
        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'NIT' => 'nullable|string|max:40',
            'razonSocial_DCD' => 'nullable|string|max:255',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
            /*
             * Cuánto efectivo deja que su domiciliario lleve encima. Vacío =
             * sin tope, que es como funciona si nadie lo toca.
             *
             * `nullable` de verdad: un cero significaría «no le despaches ni un
             * pedido contra entrega», que es una decisión legítima pero muy
             * distinta de no haber puesto tope. Se distinguen.
             */
            'max_courier_cash' => 'nullable|numeric|min:0|max:99999999',
        ];
    }
}

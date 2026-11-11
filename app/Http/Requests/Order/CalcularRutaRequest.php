<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * De donde a donde se calcula la ruta.
 *
 * `required_without:order_id`: o se nombra el pedido —y el servidor saca los
 * dos puntos— o se dan a mano. El `regex` existe porque «-74.79, 10.98» con
 * espacio o al reves llegaba y producia una ruta absurda en vez de un error.
 */
class CalcularRutaRequest extends FormRequest
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
            'order_id' => 'sometimes|nullable|integer',
            'origen' => ['required_without:order_id', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'destino' => ['required_without:order_id', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
        ];
    }
}

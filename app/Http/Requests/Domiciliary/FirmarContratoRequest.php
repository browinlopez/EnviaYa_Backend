<?php

namespace App\Http\Requests\Domiciliary;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La firma del acuerdo de vinculacion, desde la app del domiciliario.
 */
class FirmarContratoRequest extends FormRequest
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
            'signature' => 'required|string',
            'city' => 'required|string|max:120',
            // Se puede escribir la cédula en el mismo paso, como en el panel.
            'document' => 'sometimes|nullable|string|max:50',
            /*
             * La aceptación va aparte de la firma, y tiene que venir en true.
             *
             * Trazar una firma es un gesto; decir «leí y acepto» es la
             * declaración. Son dos actos distintos y el acuerdo se apoya en
             * los dos.
             */
            'acepta' => 'required|accepted',
        ];
    }
}

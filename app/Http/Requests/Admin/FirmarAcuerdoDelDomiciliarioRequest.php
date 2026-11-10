<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La firma del acuerdo de vinculacion, capturada desde el panel interno.
 *
 * La ciudad no es un adorno: el documento dice «se firma en la ciudad de ___»
 * y si hubiera que reexpedirlo tiene que salir igual.
 */
class FirmarAcuerdoDelDomiciliarioRequest extends FormRequest
{
    /*
     * El permiso lo resolvio el middleware de la ruta: `admin` y, donde
     * aplica, `modulo:<area>,gestionar`. Repetirlo aqui seria una segunda
     * fuente de verdad sobre quien puede que.
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
            // Se puede corregir la cédula en el mismo paso: el acuerdo la
            // lleva impresa y firmarlo sin ella no tiene sentido.
            'document' => 'sometimes|nullable|string|max:50',
        ];
    }
}

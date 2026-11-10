<?php

namespace App\Http\Requests\Conjunto;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quien llega a la porteria se identifica con codigo o con cedula.
 *
 * `required_without` en los dos: hace falta uno, no los dos, y sin ninguno no
 * hay a quien verificar.
 */
class VerificarEnPorteriaRequest extends FormRequest
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
            'code' => 'required_without:document|nullable|string|max:20',
            'document' => 'required_without:code|nullable|string|max:50',
        ];
    }
}

<?php

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Copiar que vende otra tienda, sin sus precios.
 */
class CopiarSurtidoRequest extends FormRequest
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
            'desde' => 'required|integer|exists:business,busines_id',
        ];
    }
}

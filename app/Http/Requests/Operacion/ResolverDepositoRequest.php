<?php

namespace App\Http\Requests\Operacion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmar o rechazar un deposito declarado.
 */
class ResolverDepositoRequest extends FormRequest
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
            'state' => 'required|in:confirmada,rechazada',
            'notes' => 'nullable|string|max:500',
        ];
    }
}

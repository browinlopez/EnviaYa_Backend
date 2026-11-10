<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mover una PQRS: prioridad, estado, a quien se asigna o como se resolvio.
 */
class ActualizarPqrsRequest extends FormRequest
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
            'priority' => 'sometimes|in:baja,media,alta',
            'state' => 'sometimes|integer|in:0,1,2,3',
            'assigned_to' => 'sometimes|nullable|integer|exists:user,user_id',
            'resolution' => 'sometimes|nullable|string',
        ];
    }
}

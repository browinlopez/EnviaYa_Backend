<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mover una solicitud: estado, a quien se asigna o como se resolvio.
 */
class ActualizarSolicitudRequest extends FormRequest
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
            'state' => 'sometimes|integer|in:0,1,2,3',
            'assigned_to' => 'sometimes|nullable|integer|exists:user,user_id',
            'resolution' => 'sometimes|nullable|string',
        ];
    }
}

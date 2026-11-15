<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Operacion\Pqrs;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Mover una PQRS: prioridad, estado, a quien se asigna o como se resolvio.
 */
class ActualizarPqrsRequest extends FormRequest
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
            'priority' => 'sometimes|in:baja,media,alta',
            'state' => 'sometimes|integer|in:'.implode(',', Pqrs::ESTADOS),
            'assigned_to' => 'sometimes|nullable|integer|exists:user,user_id',
            'resolution' => 'sometimes|nullable|string',
        ];
    }
}

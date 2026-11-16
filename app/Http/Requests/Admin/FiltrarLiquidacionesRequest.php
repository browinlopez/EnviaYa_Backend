<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Operacion\Settlement;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los filtros del listado.
 *
 * Existe por el `state`: llegaba de la query y entraba en el `where` sin
 * lista blanca, asi que `?state=99` contestaba una lista vacia como si
 * fuera una respuesta valida. La lista la da la constante del modelo, que
 * es la unica fuente de verdad de ese vocabulario.
 */
class FiltrarLiquidacionesRequest extends FormRequest
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
            'type' => 'sometimes|string',
            'state' => 'sometimes|integer|in:'.implode(',', Settlement::ESTADOS),
            'business_id' => 'sometimes|integer',
        ];
    }
}

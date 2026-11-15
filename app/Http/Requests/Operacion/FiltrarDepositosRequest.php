<?php

declare(strict_types=1);

namespace App\Http\Requests\Operacion;

use App\Models\Operacion\CashDeposit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los filtros del listado de depositos declarados.
 *
 * El `state` es una cadena y entraba en el `where` tal cual, sin lista
 * blanca: `?state=cualquiera` contestaba vacio. `CashDeposit::ESTADOS`
 * existia desde el principio y no la nombraba nadie, que es la otra mitad
 * del mismo defecto.
 */
class FiltrarDepositosRequest extends FormRequest
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
            'state' => 'sometimes|string|in:'.implode(',', CashDeposit::ESTADOS),
            'domiciliary_id' => 'sometimes|integer',
        ];
    }
}

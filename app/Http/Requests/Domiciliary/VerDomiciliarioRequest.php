<?php

declare(strict_types=1);

namespace App\Http\Requests\Domiciliary;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La ficha de un domiciliario.
 */
class VerDomiciliarioRequest extends FormRequest
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
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
        ];
    }
}

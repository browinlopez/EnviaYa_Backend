<?php

declare(strict_types=1);

namespace App\Http\Requests\Conjunto;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La foto del conjunto.
 */
class SubirFotoDelConjuntoRequest extends FormRequest
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
            // Solo imagenes: es una fachada, no un plano. El servicio corta a
            // 8 MB de todas formas.
            'file' => 'required|file|image|max:8192',
        ];
    }
}

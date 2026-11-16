<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispositivos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El token con el que un telefono recibe avisos.
 */
class GuardarTokenDelDispositivoRequest extends FormRequest
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
            'token' => 'required|string|max:255',
            'platform' => 'required|in:android,ios,web',
            'app_version' => 'nullable|string|max:20',
            'device_name' => 'nullable|string|max:80',
        ];
    }
}

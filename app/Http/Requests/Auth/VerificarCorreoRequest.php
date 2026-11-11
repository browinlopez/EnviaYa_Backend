<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El token con el que se verifica un correo.
 */
class VerificarCorreoRequest extends FormRequest
{
    /*
     * Ruta PUBLICA: no hay sesion que autorizar todavia. Lo que la protege es
     * el limitador de peticiones y que el token sea bueno, no un permiso.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => 'required|string',
        ];
    }
}

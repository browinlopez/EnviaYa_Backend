<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reenviar el correo de verificacion, desde la app.
 */
class ReenviarVerificacionRequest extends FormRequest
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
            'email' => 'required|email',
        ];
    }
}

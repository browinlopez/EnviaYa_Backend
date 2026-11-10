<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La contrasena nueva, con el codigo que llego al correo.
 *
 * `confirmed` existe para que un error de dedo no deje a alguien fuera de su
 * propia cuenta, justo cuando viene de recuperarla.
 */
class ConfirmarNuevaContrasenaRequest extends FormRequest
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
            'email' => 'required|email',
            'password' => 'required|string|min:6|confirmed',
        ];
    }
}

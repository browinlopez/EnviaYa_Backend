<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Entrar con correo y contrasena desde la app o un panel.
 */
class EntrarRequest extends FormRequest
{
    /*
     * Esta ruta es PUBLICA: todavia no hay sesion que autorizar, y de eso se
     * trata entrar. Quien puede o no puede lo decide el intento de
     * autenticacion, con su limitador de intentos.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string',
        ];
    }
}

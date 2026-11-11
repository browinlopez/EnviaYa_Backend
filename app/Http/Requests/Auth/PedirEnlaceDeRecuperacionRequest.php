<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El correo al que mandar el ENLACE de recuperación (flujo web de Breeze).
 *
 * No confundir con `PedirCodigoDeRecuperacionRequest`, que es el de la app:
 * ésa manda un CÓDIGO de seis cifras a `/v1/forgot-password` y es la que usan
 * los teléfonos. Ésta es la pantalla web que trajo Breeze, con su enlace
 * firmado.
 *
 * La regla se mueve tal cual, sin retocar nada. La fija
 * `tests/Feature/Auth/PasswordResetTest.php`, que ya existía.
 */
class PedirEnlaceDeRecuperacionRequest extends FormRequest
{
    /*
     * Ruta pública: lo que la protege es el limitador de peticiones, no un
     * permiso. Quien recupera su contraseña, por definición, no ha entrado.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}

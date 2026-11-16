<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

/**
 * La contraseña nueva, llegando por el ENLACE web (flujo de Breeze).
 *
 * No confundir con `ConfirmarNuevaContrasenaRequest`, que es el de la app:
 * ésa confirma con el código de seis cifras en `/v1/reset-password`. Ésta es
 * la pantalla web con el token del enlace firmado.
 *
 * Las reglas se mueven tal cual, `Rules\Password::defaults()` incluido: la
 * exigencia de la contraseña la decide la configuración de la aplicación en
 * un solo sitio, y escribirla aquí a mano la duplicaría.
 *
 * Lo fija `tests/Feature/Auth/PasswordResetTest.php`, que ya existía.
 */
class CambiarContrasenaConEnlaceRequest extends FormRequest
{
    /*
     * Ruta pública: lo que la protege es el token del enlace, que se
     * comprueba al usarlo, más el limitador de peticiones. Quien llega aquí
     * no ha entrado todavía.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ];
    }
}

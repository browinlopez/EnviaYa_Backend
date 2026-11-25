<?php

declare(strict_types=1);

namespace App\Http\Requests\Domiciliary;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La foto de la entrega.
 *
 * Validaba en línea dentro del controlador; el acuerdo del proyecto es que
 * cada endpoint nuevo estrene su FormRequest (ver
 * `scripts/validaciones_en_linea.py`). Las reglas son las mismas.
 */
class SubirEvidenciaRequest extends FormRequest
{
    /** La puerta la cuida la ruta (sesión y pertenencia al negocio). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * `image` ya exige que sea una imagen de verdad y no un .php con
             * otro nombre. 4 MB es el tope del servicio: esto se sube desde
             * la calle, con la red del teléfono y el cliente esperando.
             */
            'foto' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'foto.required' => 'Falta la foto.',
            'foto.image' => 'Eso no es una imagen.',
            'foto.max' => 'La foto supera los 4 MB. Vuelve a tomarla con menos calidad.',
        ];
    }
}
